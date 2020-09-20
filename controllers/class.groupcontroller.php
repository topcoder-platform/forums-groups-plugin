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
        $GroupID = (is_numeric($GroupID) && $GroupID > 0) ? $GroupID : 0;
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }

        // Setup head
         Gdn_Theme::section('Group');

        // Load the discussion record
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

        //Don't use WhereRecent due to load all data including announce.
        $discussions = $discussionModel->get(0, c('Vanilla.Discussions.PerPage', 30), $wheres);
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
        if(!$this->GroupModel->canAddGroup()) {
            throw permissionException();
        }
        $this->title(t('New Group'));
        // Use the edit form without groupID
        $this->View = 'Edit';
        $this->edit();
    }

    /**
     * Remove a group.
     * @param bool $GroupID
     * @throws Gdn_UserException
     */
    public function delete($GroupID = false) {
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canDelete($Group)) {
            throw permissionException();
        }
        $this->title(t('Delete Group'));

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
     */
    public function edit($groupID = false) {
        if ($this->title() == '') {
            $this->title(t('Edit Group'));
        }

        $Group = $this->GroupModel->getByGroupID($groupID);
        if(!$groupID) {
            $Group->OwnerID = Gdn::session()->UserID;
            $Group->LeaderID = Gdn::session()->UserID;
        } else {
            if(!$this->GroupModel->canEdit($Group)) {
                throw permissionException();
            }

        }
        $this->setData('Breadcrumbs', [['Name' => t('Groups'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => $Group->Name ? $Group->Name: $this->title() ]]);

        // Set the model on the form.
        $this->Form->setModel($this->GroupModel);

        // Make sure the form knows which item we are editing.
        $this->Form->addHidden('GroupID', $groupID);
        $this->Form->addHidden('OwnerID', $Group->OwnerID);

        // If seeing the form for the first time...
        if ($this->Form->authenticatedPostBack() === false) {
            // Get the group data for the requested $GroupID and put it into the form.
            $this->Form->setData($Group);
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

        $this->allowJSONP(true);
        Gdn_Theme::section('Group');
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }

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

        if(!$this->GroupModel->canRemoveMember($Group)) {
            throw permissionException();
        }

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

        if(!$this->GroupModel->canChangeGroupRole($Group)) {
            throw permissionException();
        }

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

        if(!$this->GroupModel->canJoin($Group)) {
            throw permissionException();
        }

        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $this->GroupModel->join($GroupID, Gdn::session()->UserID);
            $this->setRedirectTo(GroupsPlugin::GROUP_ROUTE.$GroupID);
        }
        $this->render();
    }

    /**
     * Join a group
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function invite($GroupID) {
         $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canInviteNewMember($Group)) {
            throw permissionException();
        }
        $this->setData('Group', $Group);

        if ($this->Form->authenticatedPostBack(true)) {
            $username = $this->Form->getFormValue('Username');
            if($username) {
                $userModel = Gdn::userModel();
                $user = $userModel->getByUsername($username);
                if (!$user) {
                    $this->Form->addError('User wasn\'t found.');
                } else {
                    if($user->UserID == Gdn::session()->UserID) {
                        $this->Form->addError('You are a member of this group.');
                    } else {
                        try {
                            if($this->GroupModel->isMemberOfGroup($user->UserID, $GroupID)) {
                                $this->Form->addError('User is a member of this group.');
                            } else {
                                $this->GroupModel->invite($GroupID, $user->UserID);
                                $this->render('invitation_sent');
                            }
                        } catch (\Exception $e) {
                            $this->Form->addError('Error' . $e->getMessage());
                        }
                    }
                }
            } else {
                $this->Form->validateRule('Username', 'ValidateRequired', t('You must provide Username.'));
            }
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
       if(!$this->GroupModel->canLeave($Group)) {
            throw permissionException();
       }

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
     * Accept a group invitation
     * @param $GroupID
     * @param $UserID
     * @throws Gdn_UserException
     */
    public function accept($GroupID, $UserID) {
        $Group = $this->findGroup($GroupID);
        if(!$this->GroupModel->isMemberOfGroup($UserID, $GroupID)) {
            $this->GroupModel->accept($GroupID, $UserID);
        }
        redirectTo(GroupsPlugin::GROUP_ROUTE.$GroupID);
    }


    /**
     * Default all group discussions view: chronological by most recent comment.
     *
     * @param int $Page Multiplied by PerPage option to determine offset.
     */
    public function discussions($GroupID='',$Page = false) {
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }

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

        $this->title('Group Discussions');

        // Set criteria & get discussions data
        $this->setData('Category', false, true);
        $DiscussionModel = new DiscussionModel();
        $DiscussionModel->setSort(Gdn::request()->get());
        $DiscussionModel->setFilters(Gdn::request()->get());
        $this->setData('Sort', $DiscussionModel->getSort());
        $this->setData('Filters', $DiscussionModel->getFilters());

        // Check for individual categories.
        $categoryIDs = [$Group->CategoryID];
        // Fix to segregate announcement conditions until announcement caching has been reworked.
        // See https://github.com/vanilla/vanilla/issues/7241
        $where = $announcementsWhere = ['d.GroupID'=> $GroupID, ];

        $countDiscussionsWhere = ['d.GroupID'=> $GroupID, 'Announce'=> [0,1]];
        // Get Discussion Count
        $CountDiscussions = $DiscussionModel->getCount($countDiscussionsWhere);

        $this->checkPageRange($Offset, $CountDiscussions);

        if ($MaxPages) {
            $CountDiscussions = min($MaxPages * $Limit, $CountDiscussions);
        }

        $this->setData('CountDiscussions', $CountDiscussions);

        // Get Discussions
        $this->DiscussionData = $DiscussionModel->get($where, $Limit, $Offset);
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

        if(!$this->GroupModel->canAddAnnouncement($Group)) {
            throw permissionException();
        }

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

        if(!$this->GroupModel->canAddDiscussion($Group)) {
            throw permissionException();
        }

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
        if($isAnnouncement === true) {
            // User has to have 'Vanilla.Discussions.Announce' for Groups Category
            $announce = 2;
        }

        $this->addJsFile('jquery.autosize.min.js');
        $this->addJsFile('autosave.js');
        $this->addJsFile('post.js');

        $currentFormName = "discussion";
        $this->setData('CurrentFormName', $currentFormName);
        $this->setData('Announce', $announce);
        $this->setData('Group', $Group);
        $categoryModel = new CategoryModel();
        $category = $categoryModel->getByCode('groups');
        $this->setData('Category', $category);
        $categoryID = val('CategoryID', $category);
        $this->Form->Action = '/post/discussion?categoryUrlCode=groups';
        $this->Form->addHidden('GroupID', $Group->GroupID);
        $this->Form->addHidden('Announce', $announce);
        $this->Form->addHidden('CategoryID', $categoryID);

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
