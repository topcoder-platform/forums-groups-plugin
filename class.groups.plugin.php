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

    /**
     * Database updates.
     */
    public function structure() {
        include __DIR__.'/structure.php';
    }

    /**
     * Run once on enable.
     */
    public function setup() {
        $this->structure();
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

 }

