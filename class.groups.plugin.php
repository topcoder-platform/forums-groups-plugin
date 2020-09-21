<?php
/**
 * Class GroupsPlugin
 */

use Garden\Container\Reference;
use Garden\Schema\Schema;
use Garden\Web\Exception\ClientException;
use Vanilla\ApiUtils;
use Garden\Container\Container;


class GroupsPlugin extends Gdn_Plugin {
    const GROUPS_ROUTE = '/groups';
    const GROUP_ROUTE = '/group/';
    const GROUPS_GROUP_ADD_PERMISSION = 'Groups.Group.Add';
    const GROUPS_MODERATION_MANAGE_PERMISSION = 'Groups.Moderation.Manage';
    const GROUPS_EMAIL_INVITATIONS_PERMISSION = 'Groups.EmailInvitations.Add';

    /**
     * Database updates.
     */
    public function structure() {
        include __DIR__.'/structure.php';
    }

    /**
     * Run once on enable.
     * Vanilla Installation setup enables the plugin before setting Garden.Installed=true and
     * inserting initial data in DB (see setupController_installed_handler)
     *
     */
    public function setup() {
        // Initial data hasn't been inserted
        if(!c('Garden.Installed')) {
            return;
        }
        $this->structure();
    }

    /**
     * Update DB after 'Installed' event
     * @param $sender
     * @param $args
     */
    public function setupController_installed_handler($sender, $args) {
        $this->setup();
    }

    /**
     * OnDisable is run whenever plugin is disabled.
     *
     * We have to delete our internal route because our custom page will not be
     * accessible any more.
     *
     * @return void.
     */
    public function onDisable() {
        // nothing
    }

    public function base_render_before($sender) {
        $sender->addJsFile('vendors/prettify/prettify.js', 'plugins/Groups');
        $sender->addJsFile('dashboard.js', 'plugins/Groups');
    }

    /**
     * Load CSS into head for the plugin
     * @param $sender
     */
    public function assetModel_styleCss_handler($sender) {
        $sender->addCssFile('groups.css', 'plugins/Groups');
    }

    /**
     * The settings page for the topcoder plugin.
     *
     * @param Gdn_Controller $sender
     */
    public function settingsController_groups_create($sender) {
        $cf = new ConfigurationModule($sender);
        $cf->initialize([
            'Vanilla.Groups.PerPage' => ['Control' => 'TextBox', 'Default' => '30', 'Description' => 'Groups per a page'],
        ]);

        $sender->setData('Title', sprintf(t('%s Settings'), 'Groups'));
        $cf->renderAll();
    }

    private function discussionModelWhere($requestPath, $args){
        $wheres = [];
        if(array_key_exists('Wheres', $args)) {
            $wheres =  &$args['Wheres'];
        }
        if(strpos($requestPath, 'discussions/mine') === 0) {
            // show all my discussions
        } else if (strpos($requestPath, 'discussions') === 0) {
            $wheres['d.GroupID'] = ['is null'];
        } else if (strpos($requestPath, 'categories/groups') === 0) {
            //checkPermissions
            $groupModel = new GroupModel();
            $userGroups = $groupModel->memberOf(Gdn::session()->UserID);
            $publicGroupIDs = $groupModel->getAllPublicGroupIDs();
            $userGroupsIDs = array();
            foreach ($userGroups as $userGroup) {
                array_push($userGroupsIDs, $userGroup->GroupID);
            }

            $showGroupsIDs = array_merge($userGroupsIDs, $publicGroupIDs);
            $this->log('discussionModelWhere',  ['userGroups' => $userGroups, 'publicGroupsIDs'=> $publicGroupIDs,  'showGroupIDs' => $showGroupsIDs]);
            $wheres['d.GroupID'] =  $showGroupsIDs;
        }

    }

    public function discussionModel_beforeGet_handler($sender, $args) {
        $this->discussionModelWhere(Gdn::request()->path(), $args);
    }

    public  function discussionModel_beforeGetCount_handler($sender, $args){
        $this->discussionModelWhere(Gdn::request()->path(), $args);
    }

    public  function discussionModel_beforeGetAnnouncements_handler($sender, $args){
        //FIX: it throws exceptions
        // $this->discussionModelWhere(Gdn::request()->path(), $args);
    }

    public function discussionController_render_before($sender, $args) {
        $Discussion = $sender->data('Discussion');
        if($Discussion && $Discussion->GroupID != null) {
            $groupModel = new GroupModel();
            $Group = $groupModel->getByGroupID($Discussion->GroupID);
            if (!$groupModel->canView($Group)) {
                throw permissionException();
            }
        }

    }

    public function base_BeforeCommentForm_handler($sender, $args) {
        if($sender instanceof DiscussionController &&  $sender->Form->Action === '/post/comment/') {
            $categoryID = $sender->data('Discussion.CategoryID');
            $groupID = $sender->data('Discussion.GroupID');
            $groupModel = new GroupModel();
            if(!$groupModel->canAddComment($categoryID, $groupID)) {
                $cssClass = &$args['FormCssClass'];
                $cssClass = 'hidden';
            }
        }
    }

    /**
     * The '...' discussion dropdown options
     */
    public function base_discussionOptionsDropdown_handler($sender, $args){
        $Discussion = $args['Discussion'];
        if($Discussion) {
            $groupModel = new GroupModel();
            $canEdit = $groupModel->canEditDiscussion($Discussion);
            $canDelete = $groupModel->canDeleteDiscussion($Discussion);

            $canDismiss = $groupModel->canDismissDiscussion($Discussion);
            $canAnnounce = $groupModel->canAnnounceDiscussion($Discussion);
            $canSink = $groupModel->canSinkDiscussion($Discussion);
            $canClose = $groupModel->canCloseDiscussion($Discussion);
            $canMove = $groupModel->canMoveDiscussion($Discussion);
            $canRefetch = $groupModel->canRefetchDiscussion($Discussion);

            $options = &$args['DiscussionOptionsDropdown'];

            if ($canDelete === false) {
                $options->removeItem('delete');
            }

            if($canEdit === false) {
                $options->removeItem('edit');
            }

            if ($canDismiss === false) {
                $options->removeItem('dismiss');
            }

            if ($canAnnounce === false) {
                $options->removeItem('announce');
            }

            if ($canSink === false) {
                $options->removeItem('sink');
            }

            if ($canClose === false) {
                $options->removeItem('close');
            }

            if ($canMove === false) {
                $options->removeItem('move');
            }

            if ($canRefetch === false) {
                $options->removeItem('refetch');
            }

            //$this->log('discussionController_discussionOptionsDropdown_handler', ['Discussion' => $Discussion->DiscussionID,
            //    'canDelete' => $canDelete, 'canEdit' => $canEdit, 'canDiscmiss' => $canDismiss,
            //    'canAnnounce' =>$canAnnounce, 'canSink' => $canSink, 'canMove' => $canMove, 'canFetch' => $canRefetch ]);
        }
    }

    public function discussionsController_afterDiscussionFilters_handler($sender){
        $this->addGroupLinkToMenu();
    }

    public function discussionController_afterDiscussionFilters_handler($sender){
        $this->addGroupLinkToMenu();
    }

    public function categoriesController_afterDiscussionFilters_handler($sender){
        $this->addGroupLinkToMenu();
    }

    public function discussionController_discussionInfo_handler($sender, $args) {
        if($sender->Data['Discussion']) {
            $groupID = $sender->Data['Discussion']->GroupID;
            if($groupID) {
                $groupModel = new GroupModel();
                $group = $groupModel->getByGroupID($groupID);
                echo anchor($group->Name, GroupsPlugin::GROUP_ROUTE.$groupID);
            }
        }

    }

    public function postController_afterDiscussionSave_handler($sender, $args) {
        if (!$args['Discussion']) {
            return;
        }
        $discussion= $args['Discussion'];
        if ($sender->deliveryType() == DELIVERY_TYPE_ALL) {
            redirectTo(GroupsPlugin::GROUP_ROUTE.$discussion->GroupID);
        } else {
            $sender->setRedirectTo(GroupsPlugin::GROUP_ROUTE.$discussion->GroupID);
        }
    }

    private function addGroupLinkToMenu() {
        echo '<li>'. anchor('Groups', GroupsPlugin::GROUPS_ROUTE).'</li>';
    }

    public function log($message, $data) {
        if (c('Debug')) {
            Logger::event(
                'groups_plugin',
                Logger::INFO,
                $message,
                $data
            );
        }
    }
 }

if (!function_exists('groupUrl')) {
    /**
     * Return a URL for a group.
     *
     * @param object|array $discussion
     * @param int|string $page
     * @param bool $withDomain
     * @return string
     */
    function groupUrl($group, $page = '', $withDomain = true) {
        $groupID = is_numeric($group)? $group : $group->GroupID;

        $result = '/group/'.$groupID;

        if ($page) {
            if ($page > 1 || Gdn::session()->UserID) {
                $result .= '/p'.$page;
            }
        }

        return url($result, $withDomain);
    }
}

