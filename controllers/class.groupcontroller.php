<?php
/**
 * Group controller
 */

use Garden\Schema\Validation;
use Garden\Schema\ValidationException;
use Garden\Web\Exception\ClientException;
use Vanilla\Message;
use Cocur\Slugify\Slugify;


/**
 * Handles accessing & displaying a single group via /group endpoint.
 */
class GroupController extends VanillaController {

    /** @var GroupModel */
    public $GroupModel;

    private $CategoryModel;

    /** @var Gdn_Form */
    public $Form;

    /** @var array Models to include. */
    public $Uses = ['Form', 'Database', 'GroupModel'];

    /** @var bool Whether or not to show the category dropdown. */
    public $ShowCategorySelector = true;


    public function __construct(CategoryModel $CategoryModel) {
        parent::__construct();
        $this->GroupModel = new GroupModel();
        $this->CategoryModel = $CategoryModel;
    }

    public function initialize() {
        if (!Gdn::session()->isValid()) {
            redirectTo('/entry/signin?Target='.urlencode($this->SelfUrl));
        }

        parent::initialize();

        /**
         * The default Cache-Control header does not include no-store, which can cause issues with outdated category
         * information (e.g. counts).  The same check is performed here as in Gdn_Controller before the Cache-Control
         * header is added, but this value includes the no-store specifier.
         */
        if (Gdn::session()->isValid()) {
            $this->setHeader('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
        }

        // Add modules
        //$this->addModule('NewDiscussionModule');
        $this->addModule('DiscussionFilterModule');
        //$this->addModule('CategoriesModule');
        $this->addModule('BookmarkedModule');
    }

    private function buildBreadcrumb($Group){
        $level1 = GroupsPlugin::UI[$Group->Type]['BreadcrumbLevel1Title'];
        $level1Url = GroupsPlugin::UI[$Group->Type]['BreadcrumbLevel1Url'];
        return [['Name' => $level1, 'Url' => $level1Url],
            ['Name' => $Group->Name, 'Url' => GroupsPlugin::GROUP_ROUTE.$Group->GroupID]];
    }


    private function convertToGroupID($id) {
        if(is_numeric($id) && $id > 0) {
            return $id;
        }

        if($this->isValidUuid($id) === true) {
            $categoryModel = new CategoryModel();
            $category = $categoryModel->getByCode($id);
            return val('GroupID', $category, 0);
        }

        return 0;
    }

    private function isValidUuid($uuid) {
        if(!is_string($uuid)) {
            return false;
        }
        if (!\preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $uuid)) {
            return false;
        }
        return true;
    }

    /**
     * Default single group display.
     *
     * @param int $GroupID Unique group ID
     */
    public function index($GroupID = '') {
        //Support MFE
        $GroupID = $this->convertToGroupID($GroupID);
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }

        // Setup head
         Gdn_Theme::section('Group');

        // Load the discussion record
        $this->setData('Group', $Group, true);

        $this->title($Group->Name);
        $category = $this->CategoryModel->getByCode($Group->ChallengeID);
        $categoryID= val('CategoryID', $category);
        $this->setData('GroupCategoryID',  $categoryID);
        // https://github.com/topcoder-platform/forums/issues/674
        if(hideInMFE()) {
            $this->setData('Breadcrumbs',[]);
        } else {
            $this->setData('Breadcrumbs', $this->buildBreadcrumbs($categoryID));
        }

        $roleResources = $this->data('ChallengeRoleResources');
        $resources = $this->data('ChallengeResources');
        $copilots = $this->getCopilots($resources, $roleResources);
        $this->setData('Copilots', $copilots);
        $groupDiscussions =  $this->GroupModel->getGroupDiscussionCategories($Group);
        $defaultDiscussionUrl= $defaultAnnouncementUrl = '/post/discussion/';
        if($Group->Type == GroupModel::TYPE_REGULAR) {
            if(count($groupDiscussions) > 0) {
                $defaultDiscussionUrl .= $groupDiscussions[0]['UrlCode'];
            }
        } else if($Group->Type == GroupModel::TYPE_CHALLENGE) {
            if(count($groupDiscussions) == 1) {
                $defaultAnnouncementUrl .= $groupDiscussions[0]['UrlCode'];
            } else {
                foreach ($groupDiscussions as $groupDiscussion) {
                    if ($groupDiscussion['Name'] == 'Code Questions') {
                        $defaultAnnouncementUrl .= $groupDiscussion['UrlCode'];
                        break;
                    }
                }
            }
        }

        $CategoryIDs = $this->GroupModel->getAllGroupCategoryIDs($Group->GroupID);
        $Categories = $this->CategoryModel->getFull($CategoryIDs)->resultArray();
        $this->setData('Categories', $Categories);

        // Get category data and discussions

        $this->DiscussionsPerCategory = c('Vanilla.Discussions.PerCategory', 5);
        $DiscussionModel = new DiscussionModel();
        $DiscussionModel->setSort(Gdn::request()->get());
        $DiscussionModel->setFilters(Gdn::request()->get());
        $this->setData('Sort', $DiscussionModel->getSort());
        $this->setData('Filters', $DiscussionModel->getFilters());

        $this->CategoryDiscussionData = [];
        $Discussions = [];

        foreach ($this->CategoryData->result() as $Category) {
            if ($Category->CategoryID > 0) {
                $this->CategoryDiscussionData[$Category->CategoryID] = $DiscussionModel->get(0, $this->DiscussionsPerCategory,
                    ['d.CategoryID' => $Category->CategoryID, 'Announce' => '0']);
                $Discussions = array_merge(
                    $Discussions,
                    $this->CategoryDiscussionData[$Category->CategoryID]->resultObject()
                );
            }
        }
        $this->setData('Discussions', $Discussions);

        // render dropdown options
        $this->ShowOptions = true;

        $this->setData('DefaultAnnouncementUrl', $defaultAnnouncementUrl.'?announce=1');
        $this->setData('DefaultDiscussionUrl', $defaultDiscussionUrl);

        // Find all discussions with content from after DateMarkedRead.
        $discussionModel = new DiscussionModel();
        $categoryIDs = $this->GroupModel->getAllGroupCategoryIDs($Group->GroupID);
        $announcementsWheres = ['d.CategoryID' => $categoryIDs, 'd.Announce > '=> 0];
        $announcements = $discussionModel->getAnnouncements($announcementsWheres );
        $this->setData('Announcements', $announcements);

        $Path = $this->fetchViewLocation('helper_functions', 'discussions', 'vanilla', false);
        if ($Path) {
            include_once $Path;
        }

        // For GetOptions function
        $Path2 = $this->fetchViewLocation('helper_functions', 'categories', 'vanilla', false);
        if ($Path2) {
            include_once $Path2;
        }
        $this->render();
    }

    public function __get($name) {
        switch ($name) {
            case 'CategoryData':
//            deprecated('CategoriesController->CategoryData', "CategoriesController->data('Categories')");
                $this->CategoryData = new Gdn_DataSet($this->data('Categories'), DATASET_TYPE_ARRAY);
                $this->CategoryData->datasetType(DATASET_TYPE_OBJECT);
                return $this->CategoryData;
        }
    }

    private function getCopilots($resources = null, $roleResources = null) {
        $copilots = [];
        $roleName = 'copilot';
        if (isset($resources) && isset($roleResources)) {
            $copilotRoleResources = array_filter($roleResources, function ($k) use ($roleName) {
                return $roleName == strtolower($k->name);
            });

            $copilotRoleResource = reset($copilotRoleResources);
            $memberResources = array_filter($resources, function ($k) use ($copilotRoleResource) {
                return $k->roleId == $copilotRoleResource->id;
            });
            foreach ($memberResources as $memberResourceId => $memberResource) {
                array_push($copilots, $memberResource->memberHandle);
            }
        }
        return array_unique($copilots);
    }

    /**
     * Create new group.
     *
     */
    public function add($type = '') {
        if(!$this->GroupModel->canAddGroup()) {
            throw permissionException();
        }
        if($type && array_key_exists($type, GroupsPlugin::UI)) {
            $this->title(GroupsPlugin::UI[$type]['CreateGroupTitle']);
            $level1Title = GroupsPlugin::UI[$type]['BreadcrumbLevel1Title'];
            $level1Url = GroupsPlugin::UI[$type]['BreadcrumbLevel1Url'];
            $this->setData('Breadcrumbs', [['Name' => $level1Title, 'Url' => $level1Url]]);
        }

        // Use the edit form without groupID
        $this->View = 'Edit';

        $this->edit(false);
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

        $this->title(GroupsPlugin::UI[$Group->Type]['DeleteGroupTitle']);
        $this->setData('Group', $Group);
       // Make sure the form knows which item we are deleting.
        $this->Form->addHidden('GroupID', $Group->GroupID);

        if ($this->Form->authenticatedPostBack()) {
            if ($this->Form->errorCount() == 0) {
                $this->GroupModel->deleteID($Group->GroupID);
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
        Gdn_Theme::section('Group');
        $Group  = false;
        if($groupID) {
            $Group = $this->GroupModel->getByGroupID($groupID);
            if(!$this->GroupModel->canEdit($Group)) {
                throw permissionException();
            }
            $this->title(GroupsPlugin::UI[$Group->Type]['EditGroupTitle']);
            $this->setData('Breadcrumbs', $this->buildBreadcrumb($Group));

        } else {
            $Group->Type = GroupModel::TYPE_REGULAR;
            $Group->OwnerID = Gdn::session()->UserID;
            $Group->LeaderID = Gdn::session()->UserID;
        }

        // Set Privacy Types
        $type = GroupsPlugin::UI[$Group->Type]['TypeName'];
        $privacyTypes = [GroupModel::PRIVACY_PUBLIC => sprintf('Public. Anyone can see the %s and its content. Anyone can join.', $type),
            GroupModel::PRIVACY_PRIVATE => sprintf('Private. Anyone can see the %s, but only members can see its content. People must apply or be invited to join.', $type),
            GroupModel::PRIVACY_SECRET => sprintf('Secret. Only members can see the %s and view its content. People must be invited to join.', $type)];
        $this->setData('PrivacyTypes', $privacyTypes);

        // Set Type dropbox
        if($Group->Type == GroupModel::TYPE_REGULAR || $groupID === false) { // Regular Groups can be created from UI only
            $typesData = [GroupModel::TYPE_REGULAR => GroupModel::TYPE_REGULAR];
        } else if ($Group->Type == GroupModel::TYPE_CHALLENGE){
            $typesData = [GroupModel::TYPE_CHALLENGE => GroupModel::TYPE_CHALLENGE];
        }
        $this->setData('Types', $typesData);

        // Set the model on the form.
        $this->Form->setModel($this->GroupModel);

        // Make sure the form knows which item we are editing.
        $this->Form->addHidden('GroupID', $groupID);
        $this->Form->addHidden('OwnerID', $Group->OwnerID);

        if ($this->Form->authenticatedPostBack(true)) { //The form was submitted
            if (!Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_ADD_PERMISSION)) {
                $this->Form->addError('You do not have permission to add a group');
            }

            if ($this->Form->errorCount() == 0) {
                // If the form has been posted back...
                $isIconUploaded = $this->Form->saveImage('Icon');
                $isBannerUploaded = $this->Form->saveImage('Banner');
                $data = $this->Form->formValues();
                if ($groupID = $this->GroupModel->save($data)) {
                    $this->Form->setValidationResults($this->GroupModel->validationResults());
                    if($groupID) {
                        $this->setRedirectTo('group/' . $groupID);
                    }
                    $this->View = 'Edit';
                } else {
                    $this->Form->setValidationResults($this->GroupModel->validationResults());
                }

            } else {
                $this->errorMessage($this->Form->errors());
            }
        }  else {
            // Get the group data for the requested $GroupID and put it into the form.
            $this->Form->setData($Group);
        }

        $this->render();
    }


    /**
     * The list of members
     *
     */
    // FIX: https://github.com/topcoder-platform/forums/issues/561
    // Hide members endpoint and view
    /*
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

        $breadcrumb = $this->buildBreadcrumb($Group);
        $breadcrumb[] = ['Name' => t('Members')];
        $this->setData('Breadcrumbs', $breadcrumb);

        $this->title(t('Members'));

        $this->render();
    }
     */

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
            $this->Form->addError('Failed to remove a member from "'.$Group->Name.'".');
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
                        $this->Form->addError('You are a member of "'.$Group->Name.'".');
                    } else {
                        try {
                            if(GroupModel::isMemberOfGroup($user->UserID, $GroupID)) {
                                $this->Form->addError('User is a member of "'.$Group->Name.'".');
                            } else {
                                $groupInvitation['GroupID'] = $GroupID;
                                $groupInvitation['InviteeUserID'] = $user->UserID;
                                $groupInvitationModel = new GroupInvitationModel();
                                $result = $groupInvitationModel->save($groupInvitation);
                                if($result) {
                                    $this->informMessage('Invitation was sent.');
                                } else {
                                    $validationErrors = $groupInvitationModel->validationResults();
                                    $validation = new Validation();
                                    foreach ($validationErrors as $field => $errors) {
                                        foreach ($errors as $error) {
                                            $validation->addError(
                                                $field,
                                                $error
                                            );
                                        }
                                    }
                                   $this->Form->addError($validation->getMessage());
                                   $this->render();
                                }

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
                $this->Form->addError('Failed to leave "'.$Group->Name.'".');
            } else {
                $this->setRedirectTo(GroupsPlugin::GROUPS_ROUTE);
            }
        }
        $this->render();
    }

    /**
     * Follow all group's categories
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function follow($GroupID) {
        $Group = $this->findGroup($GroupID);
        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }
        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $this->GroupModel->followGroup($Group, Gdn::session()->UserID);
            $this->setRedirectTo('group/' . $GroupID);
        }
        $this->render();
      }

    /**
     * Unfollow all group's categories
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function unfollow($GroupID) {
        $Group = $this->findGroup($GroupID);
        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }
        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $this->GroupModel->unfollowGroup($Group, Gdn::session()->UserID);
            $this->setRedirectTo('group/'.$GroupID);
        }
        $this->render();
    }

    /**
     * Watch all group's categories
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function watch($GroupID) {
        $Group = $this->findGroup($GroupID);
        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }
        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $this->GroupModel->watchGroup($Group, Gdn::session()->UserID);
            // Stay in the previous page
            // if(isset($_SERVER['HTTP_REFERER'])) {
            //    $previous = $_SERVER['HTTP_REFERER'];
            //    $this->setRedirectTo($previous);
            //} else {
            //    $this->setRedirectTo('group/'.$GroupID);
           // }
            $userMetaModel = new UserMetaModel();
            $discussionModel = new DiscussionModel();
            $CountBookmarks = $discussionModel->userBookmarkCount(Gdn::session()->UserID);
            $CountWatchedCategories = $userMetaModel->userWatchedCategoriesCount(Gdn::session()->UserID);
            $TotalCount = $CountBookmarks + $CountWatchedCategories;
            // FIX: https://github.com/topcoder-platform/forums/issues/479
            $CountWatchesHtml = myWatchingMenuItem($TotalCount);
            include_once $this->fetchViewLocation('helper_functions', 'group');
            $this->jsonTarget('#MyWatching', $CountWatchesHtml, 'ReplaceWith');

            $markup = watchGroupButton($Group);
            $this->jsonTarget("!element", $markup, 'ReplaceWith');

        }
        $this->render();
    }


    /**
     * Unwatch all group's categories
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function unwatch($GroupID) {
        $Group = $this->findGroup($GroupID);
        if(!$this->GroupModel->canView($Group)) {
            throw permissionException();
        }
        $this->setData('Group', $Group);
        if ($this->Form->authenticatedPostBack(true)) {
            $this->GroupModel->unwatchGroup($Group, Gdn::session()->UserID);
            // Stay in the previous page
            // if(isset($_SERVER['HTTP_REFERER'])) {
            //     $previous = $_SERVER['HTTP_REFERER'];
            //     $this->setRedirectTo($previous);
            // } else {
            //     $this->setRedirectTo('group/'.$GroupID);
            // }
            $userMetaModel = new UserMetaModel();
            $discussionModel = new DiscussionModel();
            $CountBookmarks = $discussionModel->userBookmarkCount(Gdn::session()->UserID);
            $CountWatchedCategories = $userMetaModel->userWatchedCategoriesCount(Gdn::session()->UserID);
            $TotalCount = $CountBookmarks + $CountWatchedCategories;
            // FIX: https://github.com/topcoder-platform/forums/issues/479
            $CountWatchesHtml = myWatchingMenuItem($TotalCount);
            include_once $this->fetchViewLocation('helper_functions', 'group');
            $this->jsonTarget('#MyWatching', $CountWatchesHtml, 'ReplaceWith');

            $markup = watchGroupButton($Group);
            $this->jsonTarget("!element", $markup, 'ReplaceWith');
          //  $this->render('Blank', 'Utility', 'Dashboard');
        }
        $this->render();
    }

    /**
     * Accept a group invitation
     * @param $GroupID
     * @param $UserID
     * @throws Gdn_UserException
     */
    public function accept($token ='') {

        if (!Gdn::session()->isValid()) {
            redirectTo(signInUrl());
        }

        $groupInvitationModel = new GroupInvitationModel();

        $result = $groupInvitationModel->validateToken($token);
        $validationErrors = $groupInvitationModel->Validation->results();
        if (count($validationErrors) > 0) {
            $validation = new Validation();
            foreach ($validationErrors as $field => $errors) {
                foreach ($errors as $error) {
                    $validation->addError(
                        $field,
                        $error
                    );
                }
            }
            if ($validation->getErrorCount() > 0) {
                $this->setData('ErrorMessage', $validation->getMessage());
                $this->render();
            }
        } else {
            if(!GroupModel::isMemberOfGroup($result['InviteeUserID'], $result['GroupID']) ) {
                $GroupModel = new GroupModel();
                $GroupModel->join($result['GroupID'],$result['InviteeUserID']);
            }
            $result['Status'] = 'accepted';
            $result['DateAccepted'] = Gdn_Format::toDateTime();
            $groupInvitationModel->save($result);
            redirectTo(GroupsPlugin::GROUP_ROUTE.$result['GroupID']);
        }

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

        //Gdn_Theme::section('Group');

        // Remove score sort
        DiscussionModel::removeSort('top');

        $Group = $this->GroupModel->getByGroupID($GroupID);
        $this->setData('Group',$Group);
        $this->setData('Breadcrumbs', [['Name' => t('Challenge Forums'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
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
        $categoryIDs = $this->GroupModel->getAllGroupCategoryIDs($Group->GroupID);
        $countDiscussionsWhere = ['d.CategoryID' => $categoryIDs, 'Announce'=> [0,1]];
        // Get Discussion Count
        $CountDiscussions = $DiscussionModel->getCount($countDiscussionsWhere);

        $this->checkPageRange($Offset, $CountDiscussions);

        if ($MaxPages) {
            $CountDiscussions = min($MaxPages * $Limit, $CountDiscussions);
        }

        $this->setData('CountDiscussions', $CountDiscussions);

        // Get Discussions

        $where = ['d.CategoryID' => $categoryIDs, 'Announce'=> 'all' ];
        $this->DiscussionData = $DiscussionModel->get( $Offset, $Limit, $where);
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
     * Join a group
     * @param $GroupID
     * @throws Gdn_UserException
     */
    public function category($GroupID) {
        $Group = $this->findGroup($GroupID);

        if(!$this->GroupModel->canManageCategories($Group)) {
            throw permissionException();
        }

        $this->setData('Group', $Group);
        $this->Form->setModel($this->CategoryModel);
        $slugify = new Slugify();

        $parentCategory = $this->GroupModel->getRootGroupCategory($Group);
        $this->Form->addHidden('ParentCategoryID', $parentCategory->CategoryID);
        $this->Form->addHidden('DisplayAs', 'Discussions');
        $this->Form->addHidden('AllowFileUploads',1);
        $this->Form->addHidden('UrlCode','');
        $this->Form->addHidden('GroupID',$GroupID);
        if ($this->Form->authenticatedPostBack(true)) {
            if($Group->Type === GroupModel::TYPE_CHALLENGE) {
                $this->Form->setFormValue('UrlCode', $Group->ChallengeID . '-' . $slugify->slugify($this->Form->getValue('Name'), '-'));
            }
            //else {
            //    $this->Form->setFormValue('UrlCode', 'group-'.$Group->GroupID.'-'.$slugify->slugify($this->Form->getValue('Name'), '-'));
            // }
            $newCategoryID = $this->Form->save();
            if(!$newCategoryID) {
                $this->errorMessage($this->Form->errors());
            } else {
                $this->informMessage('Category was added.');
            }
        }

        $this->render('add_category');
    }

    /**
     * Get available announcement options for discussions.
     *
     *
     * @return array
     */
    public function announceOptions() {
        $result = [
            '0' => '@'.t("Don't announce.")
        ];

        if (c('Vanilla.Categories.Use')) {
            $result = array_replace($result, [
                '2' => '@'.sprintf(t('In <b>%s.</b>'), t('the category')),
                '1' => '@'.sprintf(sprintf(t('In <b>%s</b> and recent discussions.'), t('the category'))),
            ]);
        } else {
            $result = array_replace($result, [
                '1' => '@'.t('In recent discussions.'),
            ]);
        }

        return $result;
    }


    /**
     * Pre-populate the form with values from the query string.
     *
     * @param Gdn_Form $form
     * @param bool $LimitCategories Whether to turn off the category dropdown if there is only one category to show.
     */
    protected function populateForm($form) {
        $get = $this->Request->get();
        $get = array_change_key_case($get);
        $values = arrayTranslate($get, ['name' => 'Name', 'tags' => 'Tags', 'body' => 'Body']);
        foreach ($values as $key => $value) {
            $form->setValue($key, $value);
        }

        if (isset($get['category'])) {
            $category = CategoryModel::categories($get['category']);
            if ($category && $category['PermsDiscussionsAdd']) {
                $form->setValue('CategoryID', $category['CategoryID']);
            }
        }
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
