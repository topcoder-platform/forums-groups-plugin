<?php
/**
 * Group controller
 */

use Vanilla\Message;

/**
 * Handles accessing & displaying a single group via /group endpoint.
 */
class GroupController extends VanillaController {

    /** @var GroupModel */
    public $GroupModel;

    /** @var Gdn_Form */
    public $Form;

    /** @var array Models to include. */
    public $Uses = ['Form', 'Database', 'GroupModel'];


    public function __construct() {
        parent::__construct();
        $this->GroupModel = new GroupModel();
    }

    public function initialize() {
        parent::initialize();

        $this->CssClass = 'NoPanel';
        /**
         * The default Cache-Control header does not include no-store, which can cause issues with outdated category
         * information (e.g. counts).  The same check is performed here as in Gdn_Controller before the Cache-Control
         * header is added, but this value includes the no-store specifier.
         */
        if (Gdn::session()->isValid()) {
            $this->setHeader('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
        }
    }

    /**
     * Default single group display.
     *
     * @param int $GroupID Unique group ID
     */
    public function index($GroupID = '') {
        // Setup head
         Gdn_Theme::section('Group');
        // Load the discussion record
        $GroupID = (is_numeric($GroupID) && $GroupID > 0) ? $GroupID : 0;
        $Group = $this->findGroup($GroupID);
        $this->setData('Group', $Group, true);

        $this->title($Group->Name);
        $this->setData('Breadcrumbs', [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID]]);
        $this->setData('CurrentUserGroups', $this->GroupModel->memberOf(Gdn::session()->UserID));
        $this->setData('TotalMembers', $this->GroupModel->countOfMembers($GroupID));
        $this->setData('Leaders', $this->GroupModel->getLeaders($GroupID));
        $this->setData('Members', $this->GroupModel->getMembers($GroupID,[],'',30,0));

        // Find all discussions with content from after DateMarkedRead.
        $discussionModel = new DiscussionModel();
        $wheres = ['d.GroupID' => $GroupID];
        $discussions = $discussionModel->get(0, '', $wheres);
        $announcements = $discussionModel->getAnnouncements($wheres);
        $this->setData('Announcements', $announcements);
        $this->setData('Discussions', $discussions);
        $this->render();
    }

    /**
     * Create new group.
     *
     */
    public function add() {
        //TODO: check permissions

        $this->title(t('New Group'));
        // Use the edit form with no groupid specified.
        $this->View = 'Edit';
        $this->edit();
    }

    /**
     * Remove a group.
     *
     * @since 2.0.0
     * @access public
     */
    public function delete($GroupID = false) {
        //TODO: permissions
        $this->title(t('Delete Group'));

        $Group = $this->findGroup($GroupID);

       // Make sure the form knows which item we are deleting.
        $this->Form->addHidden('GroupID', $Group->GroupID);

        if ($this->Form->authenticatedPostBack()) {
            if ($this->Form->errorCount() == 0) {
                $this->GroupModel->delete($Group->GroupID);
                $this->setRedirectTo(GroupsPlugin::GROUPS_ROUTE);
            }
        }
        $this->render();
    }

    /**
     * Edit a group.
     *
     * @param int|bool $groupID
     * @since 2.0.0
     * @access public
     */
    public function edit($groupID = false) {
        if ($this->title() == '') {
            $this->title(t('Edit Group'));
        }

        $this->Group = $this->GroupModel->getByGroupID($groupID);
        if(!$groupID) {
            $this->Group->OwnerID = Gdn::session()->UserID;
            $this->Group->LeaderID = Gdn::session()->UserID;
        }
        $this->setData('Breadcrumbs', [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $this->Group->Name ? $this->Group->Name: $this->title() ]]);

        // Set the model on the form.
        $this->Form->setModel($this->GroupModel);

        // Make sure the form knows which item we are editing.
        $this->Form->addHidden('GroupID', $groupID);
        $this->Form->addHidden('OwnerID', $this->Group->OwnerID);

        // If seeing the form for the first time...
        if ($this->Form->authenticatedPostBack() === false) {
            // Get the group data for the requested $GroupID and put it into the form.
            $this->Form->setData($this->Group);
        } else {

            // If the form has been posted back...
            $this->Form->formValues();
            $this->Form->saveImage('Icon');
            $this->Form->saveImage('Banner');
            if ($groupID = $this->Form->save()) {
                if ($this->deliveryType() === DELIVERY_TYPE_DATA) {
                    $this->index($groupID);
                    return;
                }
                $this->setRedirectTo('group/'.$groupID );
            }
        }

        $this->render();
    }


    /**
     * Create new group.
     *
     * @since 2.0.0
     * @access public
     */
    public function members($GroupID = '',$Page = false) {
        //TODO: check permissions
        $this->allowJSONP(true);
        Gdn_Theme::section('Group');
        $Group = $this->findGroup($GroupID);

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);
        // Set canonical URL
        $this->canonicalUrl(url(concatSep('/',GroupsPlugin::GROUP_ROUTE.'members/'.$GroupID, pageNumber($Offset, $Limit, true, false)), true));

        $this->setData('Group', $Group);
        $this->setData('Leaders', $this->GroupModel->getLeaders($GroupID));
        $this->setData('Members', $this->GroupModel->getMembers($GroupID,['Role' => GroupModel::ROLE_MEMBER],'', $Limit, $Offset));
        $this->setData('CountMembers', $this->GroupModel->countOfMembers($GroupID,GroupModel::ROLE_MEMBER) );
        $this->setJson('Loading', $Offset.' to '.$Limit);

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        $this->EventArguments['PagerType'] = 'Pager';
        $this->fireEvent('BeforeBuildPager');
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', GroupsPlugin::GROUP_ROUTE.'members/'.$GroupID.'/{Page}');
        }
        $queryString = '';// DiscussionModel::getSortFilterQueryString($DiscussionModel->getSort(), $DiscussionModel->getFilters());
        $this->setData('_PagerUrl', $this->data('_PagerUrl').$queryString);
        $this->Pager = $PagerFactory->getPager($this->EventArguments['PagerType'], $this);
        $this->Pager->ClientID = 'Pager';
        $this->Pager->configure(
            $Offset,
            $Limit,
            $this->data('CountMembers'),
            $this->data('_PagerUrl')
        );

        PagerModule::current($this->Pager);

        $this->setData('_Page', $Page);
        $this->setData('_Limit', $Limit);

        // Deliver JSON data if necessary
        if ($this->_DeliveryType != DELIVERY_TYPE_ALL) {
            $this->setJson('LessRow', $this->Pager->toString('less'));
            $this->setJson('MoreRow', $this->Pager->toString('more'));
            $this->View = 'members';
        }

        $this->setData('Breadcrumbs', [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID], ['Name' => t('Members')]]);

        $this->title(t('Members'));

        $this->render();
    }

    /**
     * Remove a member from a group
     * @param $GroupID
     * @param $MemberID
     * @throws Gdn_UserException
     */
    public function removemember($GroupID, $MemberID) {

        $Group = $this->findGroup($GroupID);

        if ($this->GroupModel->removeMember($Group->GroupID, $MemberID) === false) {
            $this->Form->addError('Failed to remove a member from this group.');
        }

        $this->View = 'members';
        $this->members($Group->GroupID);
    }

    /**
     * Change a role
     * @param $GroupID
     * @param $Role
     * @param $MemberID
     * @throws Gdn_UserException
     */
    public function setrole($GroupID, $Role,$MemberID) {
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->setRole($Group->GroupID, $MemberID,$Role)) {
            $this->Form->addError('Failed to change a role for the member.');
        }

        $this->View = 'members';
        $this->members($Group->GroupID);
    }

    /**
     * Join a group
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function join($GroupID) {
        $Group = $this->findGroup($GroupID);

        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $result = $this->GroupModel->join($GroupID, Gdn::session()->UserID);
            $this->setRedirectTo(GroupsPlugin::GROUP_ROUTE.$GroupID);
        }
        $this->render();
    }

    /**
     * Leave a group
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function leave($GroupID) {
       $Group = $this->findGroup($GroupID);
       $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            if ($this->GroupModel->removeMember($GroupID, Gdn::session()->UserID) === false) {
                $this->Form->addError('Failed to leave this group.');
            } else {
                $this->setRedirectTo(GroupsPlugin::GROUPS_ROUTE);
            }
        }
        $this->render();
    }


    /**
     * Default all group discussions view: chronological by most recent comment.
     *
     * @param int $Page Multiplied by PerPage option to determine offset.
     */
    public function discussions($GroupID='',$Page = false) {
        $Group = $this->findGroup($GroupID);

        $this->Menu->highlightRoute('/group');
        $this->addJsFile('discussions.js', 'vanilla');

        // Inform moderator of checked comments in this discussion
        $checkedDiscussions = Gdn::session()->getAttribute('CheckedDiscussions', []);
        if (count($checkedDiscussions) > 0) {
            ModerationController::informCheckedDiscussions($this);
        }

        $this->allowJSONP(true);
        // Figure out which discussions layout to choose (Defined on "Homepage" settings page).
        $Layout = c('Vanilla.Discussions.Layout');
        switch ($Layout) {
            case 'table':
                if ($this->SyndicationMethod == SYNDICATION_NONE) {
                    $this->View = 'table';
                }
                break;
            default:
                // $this->View = 'index';
                break;
        }
        Gdn_Theme::section('Group');

        // Remove score sort
        DiscussionModel::removeSort('top');

        $Group = $this->GroupModel->getByGroupID($GroupID);
        $this->setData('Group',$Group);
        $this->setData('Breadcrumbs', [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID], ['Name' => t('Discussions')]]);

        // Check for the feed keyword.
        if ($Page === 'feed' && $this->SyndicationMethod != SYNDICATION_NONE) {
            $Page = 'p1';
        }

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Discussions.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);

        // Allow page manipulation
        $this->EventArguments['Page'] = &$Page;
        $this->EventArguments['Offset'] = &$Offset;
        $this->EventArguments['Limit'] = &$Limit;
        $this->fireEvent('AfterPageCalculation');

        // Set canonical URL
        $this->canonicalUrl(url(concatSep('/',GroupsPlugin::GROUP_ROUTE.'discussions/'.$GroupID, pageNumber($Offset, $Limit, true, false)), true));

        // We want to limit the number of pages on large databases because requesting a super-high page can kill the db.
        $MaxPages = c('Vanilla.Discussions.MaxPages');
        if ($MaxPages && $Page > $MaxPages) {
            throw notFoundException();
        }

        // Setup head.
        $this->title('Group Discussions');

        // Add modules
        $this->addModule('DiscussionFilterModule');
        $this->addModule('NewDiscussionModule');
        $this->addModule('CategoriesModule');
        $this->addModule('BookmarkedModule');
        $this->addModule('TagModule');

        $categoryModel = new CategoryModel();
        $followingEnabled = $categoryModel->followingEnabled();
        if ($followingEnabled) {
            $saveFollowing = Gdn::request()->get('save') && Gdn::session()->validateTransientKey(Gdn::request()->get('TransientKey', ''));
            $followed = paramPreference(
                'followed',
                'FollowedDiscussions',
                'Vanilla.SaveFollowingPreference',
                null,
                $saveFollowing
            );
            if ($this->SelfUrl === "discussions") {
                $this->enableFollowingFilter = true;
            }
        } else {
            $followed = false;
        }
        $this->setData('EnableFollowingFilter', $this->enableFollowingFilter);
        $this->setData('Followed', $followed);

        // Set criteria & get discussions data
        $this->setData('Category', false, true);
        $DiscussionModel = new DiscussionModel();
        $DiscussionModel->setSort(Gdn::request()->get());
        $DiscussionModel->setFilters(Gdn::request()->get());
        $this->setData('Sort', $DiscussionModel->getSort());
        $this->setData('Filters', $DiscussionModel->getFilters());

        // Check for individual categories.
        $categoryIDs = null;//$this->getCategoryIDs();
        // Fix to segregate announcement conditions until announcement caching has been reworked.
        // See https://github.com/vanilla/vanilla/issues/7241
        $where = $announcementsWhere = ['d.GroupID'=> $GroupID, ];


        if ($this->data('Followed')) {
            $followedCategories = array_keys($categoryModel->getFollowed(Gdn::session()->UserID));
            $visibleCategoriesResult = CategoryModel::instance()->getVisibleCategoryIDs(['filterHideDiscussions' => true]);
            if ($visibleCategoriesResult === true) {
                $visibleFollowedCategories = $followedCategories;
            } else {
                $visibleFollowedCategories = array_intersect($followedCategories, $visibleCategoriesResult);
            }
            $where['d.CategoryID'] = $visibleFollowedCategories;
        } elseif ($categoryIDs) {
            $where['d.CategoryID'] = $announcementsWhere['d.CategoryID'] = CategoryModel::filterCategoryPermissions($categoryIDs);
        } else {
            $visibleCategoriesResult = CategoryModel::instance()->getVisibleCategoryIDs(['filterHideDiscussions' => true]);
            if ($visibleCategoriesResult !== true) {
                $where['d.CategoryID'] = $visibleCategoriesResult;
            }
        }

        $countDiscussionsWhere = ['d.GroupID'=> $GroupID, 'Announce'=> [0,1]];
        // Get Discussion Count
        $CountDiscussions = $DiscussionModel->getCount($countDiscussionsWhere);

        $this->checkPageRange($Offset, $CountDiscussions);

        if ($MaxPages) {
            $CountDiscussions = min($MaxPages * $Limit, $CountDiscussions);
        }

        $this->setData('CountDiscussions', $CountDiscussions);

        // Get Discussions
        $this->DiscussionData = $DiscussionModel->getWhereRecent($where, $Limit, $Offset);

        $this->setData('Discussions', $this->DiscussionData, true);
        $this->setJson('Loading', $Offset.' to '.$Limit);

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        $this->EventArguments['PagerType'] = 'Pager';
        $this->fireEvent('BeforeBuildPager');
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', GroupsPlugin::GROUP_ROUTE.'discussions/'.$GroupID.'/{Page}');
        }
        $queryString = DiscussionModel::getSortFilterQueryString($DiscussionModel->getSort(), $DiscussionModel->getFilters());
        $this->setData('_PagerUrl', $this->data('_PagerUrl').$queryString);
        $this->Pager = $PagerFactory->getPager($this->EventArguments['PagerType'], $this);
        $this->Pager->ClientID = 'Pager';
        $this->Pager->configure(
            $Offset,
            $Limit,
            $this->data('CountDiscussions'),
            $this->data('_PagerUrl')
        );

        PagerModule::current($this->Pager);

        $this->setData('_Page', $Page);
        $this->setData('_Limit', $Limit);
        $this->fireEvent('AfterBuildPager');

        // Deliver JSON data if necessary
        if ($this->_DeliveryType != DELIVERY_TYPE_ALL) {
            $this->setJson('LessRow', $this->Pager->toString('less'));
            $this->setJson('MoreRow', $this->Pager->toString('more'));
            $this->View = 'discussions';
        }

        $this->render();
    }


    /**
     * Create a new announcement
     * @param string $GroupID
     * @throws Gdn_UserException
     */
    public function announcement($GroupID=''){
        $Group = $this->findGroup($GroupID);

        $this->setData('Breadcrumbs',
            [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
                ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID], ['Name' => t('New Announcement')]]);
        $this->title('New Announcement');
        $this->setDiscussionData($Group, true);
        $this->View = 'discussion';
        $this->render();
    }

    /**
     * Create a new discussion
     * @param string $GroupID
     * @throws Gdn_UserException
     */
    public function discussion($GroupID=''){
        $Group = $this->findGroup($GroupID);

        $this->setData('Breadcrumbs',   [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID], ['Name' => t('New Discussion')]]);
        $this->title('New Discussion');
        $this->setDiscussionData($Group, false);
        $this->View = 'discussion';
        $this->render();

    }

    /**
     * Get an existing group by GroupID
     * @param $GroupID
     * @return stdClass
     * @throws Gdn_UserException If a Group is not found
     */
    private function findGroup($GroupID) {
        $GroupID = (is_numeric($GroupID) && $GroupID > 0) ? $GroupID : 0;
        $Group = $this->GroupModel->getByGroupID($GroupID);
        if (!is_object($Group)) {
            $this->EventArguments['GroupID'] = $GroupID;
            $this->fireEvent('GroupNotFound');
            throw notFoundException('Group');
        }

        return $Group;
    }


    private function setDiscussionData($Group, $isAnnouncement) {
        $announce = 0; // It's created in the category
        if($isAnnouncement) {
            $announce = 2;
        }

        $this->addJsFile('jquery.autosize.min.js');
        $this->addJsFile('autosave.js');
        $this->addJsFile('post.js');

        $currentFormName = "discussion";
        $this->setData('CurrentFormName', $currentFormName);
        $this->setData('Announce', $announce);
        $this->setData('Group', $Group);
        $this->Form->Action = '/post/discussion';
        //$this->setData('Forms', $forms);

        $this->Form->addHidden('GroupID', $Group->GroupID);
        $this->Form->addHidden('Announce', $announce);

        $category = CategoryModel::categories('groups');
        if ($category) {
            $categoryID = val('CategoryID', $category);
            $this->Form->addHidden('CategoryID', $categoryID);
        }

        $this->fireEvent('AfterForms');
    }


    public function log($message, $data) {
        if (c('Debug')) {
            Logger::event(
                'group_logging',
                Logger::INFO,
                $message,
                $data
            );
        }
    }

}
