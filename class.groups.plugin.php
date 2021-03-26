<?php
/**
 * Class GroupsPlugin
 */

use Garden\Container\Reference;
use Garden\Schema\Schema;
use Garden\Web\Exception\ClientException;
use Vanilla\ApiUtils;
use Garden\Container\Container;

if (!class_exists('Cocur\Slugify\Slugify')){
    require __DIR__ . '/vendor/autoload.php';
}

class GroupsPlugin extends Gdn_Plugin {
    const GROUPS_ROUTE = '/groups';
    const ROUTE_MY_GROUPS = '/groups/mine';
    const ROUTE_CHALLENGE_GROUPS = '/groups/mine?filter=challenge'; //'/groups/challenges';
    const ROUTE_REGULAR_GROUPS = '/groups/mine?filter=regular'; //'/groups/regulars';
    const GROUP_ROUTE = '/group/';
    const GROUPS_GROUP_ADD_PERMISSION = 'Groups.Group.Add';
    const GROUPS_GROUP_ARCHIVE_PERMISSION = 'Groups.Group.Archive';
    const GROUPS_GROUP_EDIT_PERMISSION = 'Groups.Group.Edit';
    const GROUPS_GROUP_DELETE_PERMISSION = 'Groups.Group.Delete';
    const GROUPS_CATEGORY_MANAGE_PERMISSION = 'Groups.Category.Manage';
    const GROUPS_MODERATION_MANAGE_PERMISSION = 'Groups.Moderation.Manage';
    const GROUPS_EMAIL_INVITATIONS_PERMISSION = 'Groups.EmailInvitations.Add';

    const ROLE_TYPE_TOPCODER = 'topcoder';
    const ROLE_TOPCODER_CONNECT_ADMIN = 'Connect Admin';
    const ROLE_TOPCODER_ADMINISTRATOR = 'administrator';
    const ROLE_TOPCODER_COPILOT = 'Connect Copilot';
    const ROLE_TOPCODER_MANAGER = 'Connect Manager';

    const UI = [
        'challenge' => ['BreadcrumbLevel1Title' => 'Challenge Forums',
            'BreadcrumbLevel1Url' =>  self::ROUTE_CHALLENGE_GROUPS,
            'CreateGroupTitle' => 'Create Challenge',
            'EditGroupTitle' => 'Edit Challenge',
            'TypeName' => 'challenge'],
        'regular' =>   ['BreadcrumbLevel1Title' => 'Group Forums',
            'BreadcrumbLevel1Url' =>  self::ROUTE_REGULAR_GROUPS,
            'CreateGroupTitle' => 'Create Group',
            'EditGroupTitle' => 'Edit Group',
            'TypeName' => 'group'],
    ];

    private $groupModel;

    /**
     * Configure the plugin instance.
     *
     */
    public function __construct(GroupModel $groupModel) {
        $this->groupModel = $groupModel;
    }

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

        $this->structure();

        //TODO: remove later
       // $this->initDefaultTopcoderRoles();
    }

    /**
     * Init all default Topcoder roles and set up permissions
     */
    private function initDefaultTopcoderRoles() {
        $requiredRoles = [self::ROLE_TOPCODER_ADMINISTRATOR, self::ROLE_TOPCODER_CONNECT_ADMIN, self::ROLE_TOPCODER_COPILOT, self::ROLE_TOPCODER_MANAGER];
        $missingRoles = [];
        RoleModel::getByName($requiredRoles, $missingRoles);
        foreach ($missingRoles as $newRole) {
            $this->defineRole(['Name' => $newRole, 'Type' => self::ROLE_TYPE_TOPCODER,  'Deletable' => '1',
                'CanSession' => '1', 'Description' => t($newRole.' Description', 'Added by Groups plugin')]);
        }

        $permissionModel = Gdn::permissionModel();
        $permissionModel->save( [
            'Role' => self::ROLE_TOPCODER_CONNECT_ADMIN,
            'Type' => self::ROLE_TYPE_TOPCODER,
            self::GROUPS_MODERATION_MANAGE_PERMISSION => 1,
            self::GROUPS_CATEGORY_MANAGE_PERMISSION => 1,
            self::GROUPS_GROUP_ADD_PERMISSION => 1,
            self::GROUPS_EMAIL_INVITATIONS_PERMISSION => 1,
            self::GROUPS_GROUP_ARCHIVE_PERMISSION => 1
        ], true);

        $permissionModel->save( [
            'Role' => self::ROLE_TOPCODER_ADMINISTRATOR,
            'Type' => self::ROLE_TYPE_TOPCODER,
            self::GROUPS_MODERATION_MANAGE_PERMISSION => 1,
            self::GROUPS_CATEGORY_MANAGE_PERMISSION => 1,
            self::GROUPS_GROUP_ADD_PERMISSION => 1,
            self::GROUPS_EMAIL_INVITATIONS_PERMISSION => 1,
            self::GROUPS_GROUP_ARCHIVE_PERMISSION => 1
        ], true);

        $permissionModel->save( [
            'Role' => self::ROLE_TOPCODER_COPILOT,
            'Type' => self::ROLE_TYPE_TOPCODER,
            self::GROUPS_CATEGORY_MANAGE_PERMISSION => 1
        ], true);

        $permissionModel->save( [
            'Role' => self::ROLE_TOPCODER_MANAGER,
            'Type' => self::ROLE_TYPE_TOPCODER,
            self::GROUPS_CATEGORY_MANAGE_PERMISSION => 1
        ], true);

        Gdn::permissionModel()->clearPermissions();
    }


    /**
     * Create a new role
     * @param $values
     */

    private function defineRole($values) {
        if(strlen($values['Name']) == 0) {
            return;
        }

        $roleModel = new RoleModel();

        // Check to see if there is a role with the same name and type.
        $roleID = $roleModel->SQL->getWhere('Role', ['Name' => $values['Name'], 'Type' => $values['Type']])->value('RoleID', null);

        if (is_null($roleID)) {
            // Figure out the next role ID.
            $maxRoleID = $roleModel->SQL->select('r.RoleID', 'MAX')->from('Role r')->get()->value('RoleID', 0);
            $roleID = $maxRoleID + 1;
            $values['RoleID'] = $roleID;

            // Insert the role.
            $roleModel->SQL->insert('Role', $values);
        }

        $roleModel->clearCache();
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

    /**
     * Add challenge/Group name in discussion item
     * @param $sender
     * @param $args
     */
    public function discussionsController_beforeDiscussionMetaData_handler($sender, $args){
        if($args['Discussion']) {
            $discussion = $args['Discussion'];
            $groupModel = new GroupModel();
            $groupID = $groupModel->findGroupIDFromDiscussion($discussion);
            GroupsPlugin::log('discussionsController_beforeDiscussionMetaData_handler', [
                'GroupID' => $groupID]);
            if ($groupID) {
                $result = self::GROUP_ROUTE . $groupID;
                $url = url($result, true);

                $group = $groupModel->getByGroupID($groupID);
                $type = ucfirst(GroupsPlugin::UI[$group->Type]['TypeName']);
                echo '<div class="Meta Meta-Discussion-Group Group-Info">'.
                        '<span class="MItem ">'.
                            '<span class="label">'.$type.':&nbsp;</span>'.
                            '<span class="value">'.anchor($group->Name, $url).'</span>'.
                        '</span>'.
                    '</div>';
            }
        }
    }

    public function base_render_before($sender) {
        $sender->addJsFile('vendors/prettify/prettify.js', 'plugins/Groups');
        $sender->addJsFile('dashboard.js', 'plugins/Groups');
     }

    public function base_groupOptionsDropdown_handler($sender, $args){
        $group = $args['Group'];
        // $currentTopcoderProjectRoles = $sender->Data['ChallengeCurrentUserProjectRoles'];
        $groupModel = new GroupModel();
        // $groupModel->setCurrentUserTopcoderProjectRoles($currentTopcoderProjectRoles);
        $groupID = $group->GroupID;
        $canEdit = $groupModel->canEdit($group) ;
        $canDelete = $groupModel->canDelete($group) ;
        $canLeave = $groupModel->canLeave($group);
        $canInviteMember = $groupModel->canInviteNewMember($group);
        $canManageMembers = $groupModel->canManageMembers($group);
        $canManageCategories = $groupModel->canManageCategories($group);
        $canFollow = $groupModel->canFollowGroup($group);
        $canWatch = $groupModel->canWatchGroup($group);
        $hasFollowed = $groupModel->hasFollowedGroup($group);
        $hasWatched = $groupModel->hasWatchedGroup($group);

       // self::log('base_groupOptionsDropdown_handler', ['Group' => $group->GroupID,
       //     'currentUserTopcoderProjectRoles' =>$currentTopcoderProjectRoles,
       //     'canDelete' => $canDelete, 'canEdit' => $canEdit, 'canLeave' => $canLeave,
       //    'canInviteMember' =>$canInviteMember, 'canManageMembers' => $canManageMembers, 'canManageCategories ' =>
       //         $canManageCategories, 'canFollow' => $canFollow ]);
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

    public function discussionController_render_before($sender, $args) {
        $Discussion = $sender->data('Discussion');
        if($Discussion) {
            $groupModel = new GroupModel();
            $groupID = $groupModel->findGroupIDFromDiscussion($Discussion);
            if($groupID) {
                $Group = $groupModel->getByGroupID($groupID);
                if (!$groupModel->canView($Group)) {
                    throw permissionException();
                }
                $sender->setData('Group', $Group);
            }
        }

    }

    /**
     * The '...' discussion dropdown options
     */
    public function base_discussionOptionsDropdown_handler($sender, $args){
        $Discussion = $args['Discussion'];
        if($Discussion) {
            // The list of Topcoder Project Roles are added to a sender by Topcoder plugin before each request
            // for DiscussionController/GroupController
            $data = $sender->Data;
           // $currentTopcoderProjectRoles = val('ChallengeCurrentUserProjectRoles', $data, []);
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
            } else {
                // User doesn't have Vanilla Moderation permission but can moderate group discussions
                $options->addLink(t('Move'), '/moderation/confirmdiscussionmoves?discussionid='.$Discussion->DiscussionID, 'move', 'MoveDiscussion Popup');
            }

            if ($canRefetch === false) {
                $options->removeItem('refetch');
            }

            self::log('discussionController_discussionOptionsDropdown_handler', ['Discussion' => $Discussion->DiscussionID,
                'canDelete' => $canDelete, 'canEdit' => $canEdit, 'canDismiss' => $canDismiss,
                'canAnnounce' =>$canAnnounce, 'canSink' => $canSink, 'canMove' => $canMove, 'canReFetch' => $canRefetch ]);
        }
    }

    public function base_beforeUserLinksDiscussionFilters_handler($sender){
        $this->addGroupLinkToMenu($sender);
    }

    public function base_categoryOptionsDropdown_handler($sender, $args) {
        if (!Gdn::session()->isValid()) {
            return ;
        }
        $dropdown = &$args['CategoryOptionsDropdown'];
        $category = &$args['Category'];

        // FIX: Hide 'Mark Read' menu item
        // https://github.com/topcoder-platform/forums/issues/125
        $dropdown->removeItem('mark-read');

        if(val('DisplayAs', $category) == 'Discussions') {
            $categoryModel = new CategoryModel();
            $categoryID = val('CategoryID', $category);
            $hasWatched = $categoryModel->hasWatched($categoryID, Gdn::session()->UserID);;
            $dropdown->addLink(
                t($hasWatched ? 'Unwatch' : 'Watch'),
                $hasWatched ? '/category/watched?categoryid='.$categoryID.'&tkey='.Gdn::session()->transientKey() : '/category/watch?categoryid='.$categoryID.'&tkey=' . Gdn::session()->transientKey(),
                'watch'
            );
       }
    }

    /**
     * Add additional links in Discussion Info
     * @param $sender
     * @param $args
     */
    public function discussionController_discussionInfo_handler($sender, $args) {
        if($sender->Data['Discussion']) {
            $groupModel = new GroupModel();
            $groupID = $groupModel->findGroupIDFromDiscussion($sender->Data['Discussion']);
            if($groupID) {
                $group = $groupModel->getByGroupID($groupID);
                if($group->ChallengeUrl) {
                    echo anchor($group->Name, $group->ChallengeUrl);
                } else {
                    echo anchor($group->Name, GroupsPlugin::GROUP_ROUTE.$groupID);
                }
            }
        }

    }

    public function postController_afterDiscussionSave_handler($sender, $args) {
        if (!$args['Discussion']) {
            return;
        }
        $discussion= $args['Discussion'];
        $groupModel = new GroupModel();
        $groupID = $groupModel->findGroupIDFromDiscussion($discussion);
        if ($sender->deliveryType() == DELIVERY_TYPE_ALL) {
            redirectTo(GroupsPlugin::GROUP_ROUTE.$groupID);
        } else {
            $sender->setRedirectTo(GroupsPlugin::GROUP_ROUTE.$groupID);
        }
    }

    /**
     * Add a challenge name and link for each category on /categories page
     * @param $sender
     * @param $args
     */
    public function categoriesController_afterChallenge_handler($sender, $args) {
        $category = $args['Category'];
        $groupID = val('GroupID', $category);
        if($groupID) {
           $group = $this->groupModel->getByGroupID($groupID);
           $type = ucfirst(GroupsPlugin::UI[$group->Type]['TypeName']);
           echo '<span>'.$type.':</span>&nbsp;'.anchor( $group->Name, self::GROUP_ROUTE.$group->GroupID);
        }
    }

    /**
     * Allows user to unwatch a category.
     * Add the Vanilla method to stay in the same page
     *
     * @param null $categoryID
     * @param null $tKey
     * @throws Gdn_UserException
     */
    public function categoryController_watched_create($sender,$categoryID = null, $tKey = null) {
        $this->watchCategory($sender, $categoryID, null, $tKey);
    }

    /**
     * Allows user to watch a category.
     * Add the Vanilla method to stay in the same page
     *
     * @param null $categoryID
     * @param null $tKey
     * @throws Gdn_UserException
     */
    public function categoryController_watch_create($sender,$categoryID = null, $tKey = null) {
        $this->watchCategory($sender, $categoryID, 1, $tKey);
    }

    private function watchCategory($sender, $categoryID = null, $watched = null,  $tKey = null) {
        // Make sure we are posting back.
        if (!$sender->Request->isAuthenticatedPostBack() && !Gdn::session()->validateTransientKey($tKey)) {
            throw permissionException('Javascript');
        }

        if (!Gdn::session()->isValid()) {
            throw permissionException('SignedIn');
        }

        $userID = Gdn::session()->UserID;

        $categoryModel = new CategoryModel();
        $category = CategoryModel::categories($categoryID);
        if (!$category) {
            throw notFoundException('Category');
        }

        $hasPermission =  CategoryModel::checkPermission($categoryID, 'Vanilla.Discussions.View');
        if (!$hasPermission) {
            throw permissionException('Vanilla.Discussion.View');
        }

        $result = $categoryModel->watch($categoryID, $watched);
        // Set the new value for api calls and json targets.
        $sender->setData([
            'UserID' => $userID,
            'CategoryID' => $categoryID,
            'Watched' => $result
        ]);

        switch ($sender->deliveryType()) {
            case DELIVERY_TYPE_DATA:
                $sender->render('Blank', 'Utility', 'Dashboard');
                return;
            case DELIVERY_TYPE_ALL:
                // Stay in the previous page
                if(isset($_SERVER['HTTP_REFERER'])) {
                    $previous = $_SERVER['HTTP_REFERER'];
                    redirectTo($previous);
                } else {
                    redirectTo('/categories');
                }
        }

        // Return the appropriate bookmark.
        /// require_once $sender->fetchViewLocation('helper_functions', 'Categories');
        $markup = watchButton($categoryID);
        $sender->jsonTarget("!element", $markup, 'ReplaceWith');
        $sender->render('Blank', 'Utility', 'Dashboard');
    }

    /**
     * Watch a category
     * @param CategoryModel $sender
     */
    public function categoryModel_watch_create(CategoryModel $sender){
        $categoryIDs = val(0, $sender->EventArguments);
        $watched = val(1, $sender->EventArguments);
        $sender->setCategoryMetaData($categoryIDs, Gdn::session()->UserID, $watched);
    }

    /**
     * Set category meta data for user
     * @param $categoryIDs array of CategoryID
     * @param $userID
     * @param $watched 1 - to watch, null - unwatched
     */
    public function categoryModel_setCategoryMetaData_create(CategoryModel $sender) {
        $categoryIDs = val(0, $sender->EventArguments);
        $userID = val(1, $sender->EventArguments);
        $watched = val(2, $sender->EventArguments);
        $userMetaModel = new UserMetaModel();
        if(is_numeric($categoryIDs) ) {
            $categoryIDs = [$categoryIDs];
        }
        foreach($categoryIDs as $categoryID) {
            $newEmailCommentKey = 'Preferences.Email.NewComment.'.$categoryID;
            $newEmailDiscussionKey = 'Preferences.Email.NewDiscussion.'.$categoryID;
            $newPopupCommentKey = 'Preferences.Popup.NewComment.'.$categoryID;
            $newPopupDiscussionKey = 'Preferences.Popup.NewDiscussion.'.$categoryID;
            $userMetaModel->setUserMeta($userID, $newEmailCommentKey , $watched);
            $userMetaModel->setUserMeta($userID, $newEmailDiscussionKey, $watched);
            $userMetaModel->setUserMeta($userID, $newPopupCommentKey , $watched);
            $userMetaModel->setUserMeta($userID, $newPopupDiscussionKey , $watched);
        }
        return;// $sender->hasWatched($categoryIDs,$userID);
    }

    /**
     * Check if the current user has watched a category or at least one category from the list
     *
     * @param $userID
     * @param $categoryIDs array|int
     * @return bool
     */
    public function categoryModel_hasWatched_create(CategoryModel $sender) {
        $categoryIDs = val(0, $sender->EventArguments);
        $userID = val(1, $sender->EventArguments);

        if(is_numeric($categoryIDs) ) {
            $categoryIDs = [$categoryIDs];
        }

        $userMetaModel = new UserMetaModel();
        foreach ($categoryIDs as $categoryID) {
            $newEmailDiscussionKey = 'Preferences.Email.NewDiscussion.' . $categoryID;
            $newEmailDiscussionValue = $userMetaModel->getUserMeta($userID, $newEmailDiscussionKey, false);
            // Values stored as text in UserMeta
            if($newEmailDiscussionValue && $newEmailDiscussionValue[$newEmailDiscussionKey] == 1) {
                return true;
            }
            $newEmailCommentKey = 'Preferences.Email.NewComment.' . $categoryID;
            $newEmailCommentValue = $userMetaModel->getUserMeta($userID, $newEmailCommentKey, false);
            // Values stored as text in UserMeta
            if($newEmailCommentValue && $newEmailCommentValue[$newEmailCommentKey] == 1) {
                return true;
            }

            $newPopupDiscussionKey = 'Preferences.Popup.NewDiscussion.' . $categoryID;
            $newPopupDiscussionValue = $userMetaModel->getUserMeta($userID, $newPopupDiscussionKey, false);
            // Values stored as text in UserMeta
            if($newPopupDiscussionValue && $newPopupDiscussionValue[$newPopupDiscussionKey] == 1) {
                return true;
            }
            $newPopupCommentKey = 'Preferences.Popup.NewComment.' . $categoryID;
            $newPopupCommentValue = $userMetaModel->getUserMeta($userID, $newPopupCommentKey, false);
            // Values stored as text in UserMeta
            if($newPopupCommentValue && $newPopupCommentValue[$newPopupCommentKey] == 1) {
                return true;
            }
        }
        return false;
    }

    //
    // EMAIL TEMPLATES
    //

    /**
     *  New discussion has been posted
     * @param $sender
     * @param $args
     */
    public function discussionModel_beforeRecordAdvancedNotification_handler($sender, $args) {
        $data = &$args['Activity'];
        if(($data['ActivityType'] == 'Discussion' && $data['RecordType'] == 'Discussion')) {
            $discussion = $args['Discussion'];
            $mediaData = $this->getMediaData('discussion', $data['RecordID']);
            GroupsPlugin::log(' TopcoderEmailTemplate:buildEmail', ['mediaData' => $mediaData]);
            $userModel = new UserModel();
            $author = $userModel->getID($discussion['InsertUserID']);
            $category = CategoryModel::categories($discussion['CategoryID']);
            $categoryName = $category['Name'];
            $groupName = '';
            $groupLink = '';
            if($category['GroupID']) {
                $groupModel = new GroupModel();
                $group =  $groupModel->getByGroupID($category['GroupID']);
                $groupName = $group->Name;
                $groupLink = $this->buildEmailGroupLink($group);
            }
            $categoryBreadcrumbs = array_column(array_values(CategoryModel::getAncestors($discussion['CategoryID'])), 'Name');
            $dateInserted = Gdn_Format::dateFull($discussion['DateInserted']);
            $headline = sprintf('The new discussion has been posted in the category %s.' , $categoryName);
            if($groupName) {
                $headline = sprintf('%s: %s', $groupName, $headline);
            }
            $data["HeadlineFormat"] = $headline;
            // Format to Html
            $message = Gdn::formatService()->renderQuote($discussion['Body'], $discussion['Format']);
            // We just converted it to HTML. Make sure everything downstream knows it.
            // Taking this HTML and feeding it into the Rich Format for example, would be invalid.
            $data['Format'] = 'Html';
            $data["Story"] =
                '<p>You are watching the category "' . $categoryName . '", ' .
                'which was updated ' . $dateInserted . ' by ' . $author->Name . ':<p/>' .
                '<hr/>' .
                '<div style="padding: 0; margin: 0">'.
                '<p>' . $groupLink .'</p>'.
                '<p><span>Discussion: ' . $discussion['Name'] . '</p>' .
                '<p><span>Author: ' . $author->Name . '</p>' .
                '<p><span>Category: ' . implode('›', $categoryBreadcrumbs) . '</p>' .
                '<p><span>Message:</span> ' . $message .'</p>'.
                '<p>'.$this->formatMediaData($mediaData) .'</p>'.
                '</div>'.
                '<hr/>';
        }
    }

    /**
     * New comment has been posted
     * @param $sender
     * @param $args
     */
    public function commentModel_beforeRecordAdvancedNotification($sender, $args){
        $data = &$args['Activity'];
        if(($data['ActivityType'] == 'Comment' && $data['RecordType'] == 'Comment')) {
            $discussion = $args['Discussion'];
            $comment = $args["Comment"];
            $userModel = new UserModel();
            $mediaData = $this->getMediaData('comment', $data['RecordID']);
            $discussionAuthor = $userModel->getID($discussion['InsertUserID']);
            $commentAuthor = $userModel->getID($comment['InsertUserID']);
            $category = CategoryModel::categories($discussion['CategoryID']);
            $discussionName = $discussion['Name'];
            $categoryName = $category['Name'];
            $groupName = '';
            $groupLink = '';
            if($category['GroupID']) {
                $groupModel = new GroupModel();
                $group =  $groupModel->getByGroupID($category['GroupID']);
                $groupName = $group->Name;
                $groupLink = $this->buildEmailGroupLink($group);
            }
            $categoryBreadcrumbs = array_column(array_values(CategoryModel::getAncestors($discussion['CategoryID'])), 'Name');
            $discussionDateInserted = Gdn_Format::dateFull($discussion['DateInserted']);
            $commentDateInserted = Gdn_Format::dateFull($comment['DateInserted']);
            $headline = $data["HeadlineFormat"];
            if($groupName) {
                $headline = sprintf('%s: %s', $groupName, $headline);
            }
            $data["HeadlineFormat"] = $headline;
            // $data["HeadlineFormat"] = 'The new discussion has been posted in the category ' . $categoryName . '.';
            // Format to Html
            $discussionStory = condense(Gdn_Format::to($discussion['Body'], $discussion['Format']));
            $commentStory = Gdn::formatService()->renderQuote($comment['Body'],$comment['Format']);
            // We just converted it to HTML. Make sure everything downstream knows it.
            // Taking this HTML and feeding it into the required format for example, would be invalid.
            $data['Format'] = 'Html';
            $data["Story"] =
                '<p>You are watching the discussion "' . $discussionName . '" in the category "' .$categoryName.'" '.
                'which was updated ' . $commentDateInserted . ' by ' . $commentAuthor->Name . ':</p>' .
                '<hr/>' .
                '<p class="label">'.$groupLink.'</p>'.
                '<p class="label"><span style="display: block">Message:</span>'.'</p>' .
                 $commentStory .
                $this->formatMediaData($mediaData).
                '<br/><hr/>';
            $parentCommentID = (int)$comment['ParentCommentID'];
            if($parentCommentID > 0) {
                $commentModel = new CommentModel();
                $parentComment = $commentModel->getID($parentCommentID, DATASET_TYPE_ARRAY);
                $parentCommentAuthor = $userModel->getID($parentComment['InsertUserID']);
                $parentCommentStory = condense(Gdn_Format::to($parentComment['Body'], $parentComment['Format']));
                $data['Story'] .=
                    '<p class="label">Original Message (by '.$parentCommentAuthor->Name.' ):</p>'.
                    '<p>' .
                        $parentCommentStory.
                    '</p>' .
                    '<hr/>';
            }
        }
    }

    // Build a group link for an email template
    private function buildEmailGroupLink($group) {
        if ($group) {
            $groupName = $group->Name;
            $groupType = ucfirst(self::UI[$group->Type]['TypeName']);
            $color = c('Garden.EmailTemplate.ButtonTextColor');
            return sprintf('<span>%s: %s </span><br/>', $groupType, anchor($groupName, url(GroupsPlugin::GROUP_ROUTE . $group->GroupID, true), '',
                ['rel' => 'noopener noreferrer', 'target' => '_blank', 'style' => 'color:' . $color]));
        }
        return '';
    }

    // Format attachments
    private function formatMediaData($mediaData){
        if(count($mediaData) == 0) {
            return '';
        }

        $output = '<span>Attachments:</span><br/>';
        foreach ($mediaData as $mediaItem) {
            $name = val('Name', $mediaItem);
            $path = val('Path', $mediaItem);
            $size = val('Size', $mediaItem);
            $formattedSize = Gdn_Upload::formatFileSize($size);
            $link = anchor($name, $path, ['rel' => 'noopener noreferrer', 'target' => '_blank']);
            $output .= sprintf('<span style="white-space: nowrap">%s (%s)</span><br/>', $link, $formattedSize);
        }
        return $output;
    }

    // Load data from Media Table
    private function getMediaData($type, $id) {
        $mediaData = [];
        $mediaModel = new MediaModel();
        if (in_array($type, ['discussion', 'comment'])) {
            if (is_numeric($id)) {
                $sqlWhere = [
                    'ForeignTable' => $type,
                    'ForeignID' => $id
                ];
                $mediaData = $mediaModel->getWhere($sqlWhere)->resultArray();
            }
        }
        return $mediaData;
    }

    // END: EMAIL TEMPLATES

    /**
     * Add Topcoder Roles
     * @param $sender
     * @param $args
     */
    public function base_userAnchor_handler($sender, $args){
        if($sender instanceof DiscussionController || $sender instanceof GroupController || $sender instanceof PostController) {
            $user = $args['User'];
            $isTopcoderAdmin = $args['IsTopcoderAdmin'];
            $anchorText = &$args['Text'];
            $resources = $sender->data('ChallengeResources');
            $roleResources = $sender->data('ChallengeRoleResources');
            $anchorText = '<span class="topcoderHandle">'.$anchorText.'</span>';
            // Don't show Topcoder Challenge roles for admin roles
            if(!$isTopcoderAdmin){
                $roles =  $this->topcoderProjectRolesText($user, $resources, $roleResources);
                if($roles) {
                    $anchorText = $anchorText . '&nbsp;<span class="challengeRoles">('.$roles. ')</span>';
                }
            }
        }
    }

    /**
     * Add Topcoder Roles
     * @param $sender
     * @param $args
     */
    public function base_userPhoto_handler($sender, $args){
        if($sender instanceof DiscussionController || $sender instanceof GroupController || $sender instanceof PostController) {
            $user = $args['User'];
            $anchorText = &$args['Title'];
            $isTopcoderAdmin = $args['IsTopcoderAdmin'];
            $resources = $sender->data('ChallengeResources');
            $roleResources = $sender->data('ChallengeRoleResources');
            // Don't show Topcoder Challenge roles for admin roles
            if(!$isTopcoderAdmin){
                $roles =  $this->topcoderProjectRolesText($user, $resources, $roleResources);
                if($roles) {
                    $anchorText = $anchorText.'&nbsp;('.$roles. ')';
                }
            }
        }
    }

    private function topcoderProjectRolesText($user, $resources = null, $roleResources = null) {
       $roles = $this->getTopcoderProjectRoles($user, $resources, $roleResources);
       // FIX: https://github.com/topcoder-platform/forums/issues/476:  Show only Copilot, Reviewer roles
       $displayedRoles =  array_intersect(array_unique($roles), ["Copilot", "Reviewer"]);
       return count($displayedRoles) > 0 ? implode(', ', $displayedRoles) : '';
    }

    /**
     * Get a list of Topcoder Project Roles for an user
     * @param $user object User
     * @param array $resources
     * @param array $roleResources
     * @return array
     */
    private function getTopcoderProjectRoles($user, $resources = null, $roleResources = null) {
        $topcoderUsername = val('Name', $user, t('Unknown'));
        $roles = [];
        if (isset($resources) && isset($roleResources)) {
            $allResourcesByMember = array_filter($resources, function ($k) use ($topcoderUsername) {
                return $k->memberHandle == $topcoderUsername;
            });
            if($allResourcesByMember) {
                foreach ($allResourcesByMember as $resource) {
                    $roleResource = array_filter($roleResources, function ($k) use ($resource) {
                        return $k->id == $resource->roleId;
                    });
                    array_push($roles, reset($roleResource)->name);
                }
            }
        }
        return $roles;
    }

    /**
     * Display a groups link in the menu
     */
    private function addGroupLinkToMenu($sender) {
        if(Gdn::session()->isValid()) {

            echo '<li class="'.$this->getMenuItemCssClassFromQuery($sender, 'challenge').'">'. anchor('Challenge Forums', GroupsPlugin::ROUTE_CHALLENGE_GROUPS).'</li>';
           // echo '<li class="'.$this->getMenuItemCssClassFromQuery($sender, 'regular').'">'. anchor('Group Discussions', GroupsPlugin::ROUTE_REGULAR_GROUPS).'</li>';
           // echo '<li class="'.$this->getMenuItemCssClassFromRequestMethod($sender, 'mine').'">'. anchor('My Challenges & Groups', GroupsPlugin::ROUTE_MY_GROUPS).'</li>';
        }
    }

    private function getMenuItemCssClassFromRequestMethod($sender, $requestMethod){
        return $sender->ControllerName == 'groupscontroller' && $sender->RequestMethod == $requestMethod ? ' Active' : '';
    }

    private function getMenuItemCssClassFromQuery($sender, $requestMethod){
        return $sender->ControllerName == 'groupscontroller' && Gdn::request()->get('filter') == $requestMethod ? ' Active' : '';
    }

    public static function logMessage($message, $data =[], $file = __FILE__, $line = __LINE__) {
        logMessage($file, $line, 'GroupsPlugin', $message, json_encode($data) );
    }
    public static function log($message, $data= []) {
        if (c('Debug')) {
            Logger::event(
                'groups_plugin',
                Logger::DEBUG,
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
if (!function_exists('wrapCheckOrRadio')) {
    function wrapCheckOrRadio($fieldName, $labelCode, $listOptions, $attributes = [])
    {
        $form = Gdn::controller()->Form;

        if (count($listOptions) == 2 && array_key_exists(0, $listOptions)) {
            unset($listOptions[0]);
            $value = array_pop(array_keys($listOptions));

            // This can be represented by a checkbox.
            return $form->checkBox($fieldName, $labelCode);
        } else {
            $cssClass = val('ListClass', $attributes, 'List Inline');

            $result = ' <b>' . t($labelCode) . "</b> <ul class=\"$cssClass\">";
            foreach ($listOptions as $value => $code) {
                $result .= ' <li>' . $form->radio($fieldName, $code, ['Value' => $value, 'class' => 'radio-inline']) . '</li> ';
            }
            $result .= '</ul>';
            return $result;
        }
    }
}