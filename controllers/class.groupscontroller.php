<?php
/**
 * Groups controller
 */

use Vanilla\Message;

/**
 * Handles accessing & displaying a single group via /groups endpoint.
 */
class GroupsController extends VanillaController {

    /** @var array Models to include. */
    public $Uses = ['Form', 'Database', 'GroupModel'];


    public function __construct() {
        parent::__construct();
    }

    public function initialize() {
        if (!Gdn::session()->isValid()) {
            redirectTo('/entry/signin?Target='.urlencode($this->SelfUrl));
        }

        parent::initialize();

        $this->Menu->highlightRoute(GroupsPlugin::GROUPS_ROUTE);
        /**
         * The default Cache-Control header does not include no-store, which can cause issues (e.g. inaccurate unread
         * status or new comment counts) when users visit the discussion list via the browser's back button.  The same
         * check is performed here as in Gdn_Controller before the Cache-Control header is added, but this value
         * includes the no-store specifier.
         */
        if (Gdn::session()->isValid()) {
            $this->setHeader('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
        }

        // Add modules
        $this->addModule('NewDiscussionModule');
        $this->addModule('DiscussionFilterModule');
        $this->addModule('CategoriesModule');
        $this->addModule('BookmarkedModule');
        $this->fireEvent('AfterInitialize');
    }

    public function setFilterPageData($filter) {
        if($filter == 'challenge') {
            $this->View = 'index';
            $this->title('Challenges');
            $this->setData('Title', 'My Challenges');
            $this->setData('AddButtonTitle', 'Challenge');
            $this->setData('AddButtonLink', '/group/add?type=challenge');
            $this->setData('AvailableGroupTitle', 'Available Challenges');
            $this->setData('MyGroupButtonTitle', 'All My Challenges');
            $this->setData('AllGroupButtonTitle', 'All Available Challenges');
            $this->SetData('MyGroupButtonLink', '/groups/mine/?filter=challenge');
            $this->setData('AllGroupButtonLink', '/groups/all/?filter=challenge');
            $this->setData('NoGroups', 'No challenges were found.');
            $this->setData('Breadcrumbs', [
                ['Name' => 'Challenges', 'Url' => GroupsPlugin::ROUTE_CHALLENGE_GROUPS]]);
        } else if($filter == 'regular' ) {
            $this->View = 'index';
            $this->title('Groups');
            $this->setData('Title', 'My Groups');
            $this->setData('AddButtonTitle', 'Group');
            $this->setData('AddButtonLink', '/group/add?type=regular');
            $this->setData('MyGroupButtonTitle', 'All My Groups');
            $this->setData('AllGroupButtonTitle', 'All Available Groups');
            $this->setData('AvailableGroupTitle', 'Available Groups');
            $this->SetData('MyGroupButtonLink', '/groups/mine/?filter=regular');
            $this->setData('AllGroupButtonLink', '/groups/all/?filter=regular');
            $this->setData('NoGroups','No groups were found.');
            $this->setData('Breadcrumbs', [
                ['Name' => t('Groups'), 'Url' =>  GroupsPlugin::ROUTE_REGULAR_GROUPS]]);
        }
    }

    public function index($Page=false, $filter) {
        DashboardNavModule::getDashboardNav()->setHighlightRoute('groups/challenges');
        $this->Menu->highlightRoute('groups/challenges');
        Gdn_Theme::section('GroupList');

        $GroupModel = new GroupModel();
        $GroupModel->setFilters(Gdn::request()->get());
        $this->setFilterPageData($filter);
        $filters =  $GroupModel->getFiltersFromKeys($GroupModel->getFilters());

        // Filter wasn't found.
        // TODO: redirect to a default page
        if(count($filters) == 0) {
           redirectTo(GroupsPlugin::ROUTE_MY_GROUPS);
        }

        $where =  $filters[0]['where'];
        GroupsPlugin::log('index:filter', ['filters' => $filters, 'where' =>$where]);
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $defaultSort = $GroupModel::getAllowedSorts()['new']['orderBy'];
        $GroupData = $GroupModel->getMyGroups($where, $defaultSort, $Limit, $Offset);
        $countOfGroups = $GroupModel->countMyGroups($where);
        $AvailableGroupData = $GroupModel->getAvailableGroups($where, $defaultSort, $Limit, $Offset);

        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));
        $this->setData('Groups', $GroupData);

        $this->setData('CountOfGroups', $countOfGroups);
        $this->setData('AvailableGroups', $AvailableGroupData);
        $this->render();
    }

    public function mine($Page = false,$filter ='') {
        if($filter != '') {
            $this->mygroups($Page, $filter);
            return;
        }

        Gdn_Theme::section('GroupList');

        list($Offset, $Limit) = offsetLimit(0, c('Vanilla.Groups.PerPage', 30), true);

        $this->title(t('My Challenges & Groups'));
        $this->setData('Breadcrumbs', [
            ['Name' => t('My Challenges & Groups'), 'Url' => GroupsPlugin::ROUTE_MY_GROUPS]]);

        $GroupModel = new GroupModel();
        $challengeGroupsWhere = $GroupModel::getAllowedFilters()['filter']['filters']['challenge']['where'];
        $defaultSort = $GroupModel::getAllowedSorts()['new']['orderBy'];
        GroupsPlugin::log('mine:filter', ['filters' => $challengeGroupsWhere]);
        $challengeGroupsData = $GroupModel->getMyGroups($challengeGroupsWhere, $defaultSort, $Limit, $Offset);
        $countOfChallengeGroups = $GroupModel->countMyGroups($challengeGroupsWhere);
        $this->setData('CountOfChallengeGroups', $countOfChallengeGroups);
        $this->setData('ChallengeGroups', $challengeGroupsData);

        $regularGroupsWhere = $GroupModel::getAllowedFilters()['filter']['filters']['regular']['where'];
        $regularGroupsData = $GroupModel->getMyGroups($regularGroupsWhere, $defaultSort, $Limit, $Offset);
        $countOfRegularGroups = $GroupModel->countMyGroups($regularGroupsWhere);
        $this->setData('RegularGroups', $regularGroupsData);
        $this->setData('CountOfRegularGroups', $countOfRegularGroups);
        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));

        $this->render();
    }

    private function mygroups($Page = false, $filter = '') {
        // Setup head
        Gdn_Theme::section('GroupList');

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);
        $GroupModel = new GroupModel();
        $GroupModel->setFilters(Gdn::request()->get());
        $filters =  $GroupModel->getFiltersFromKeys($GroupModel->getFilters());

        // Filter wasn't found.
        // TODO: redirect to a default page
        if(count($filters) == 0) {
            redirectTo(GroupsPlugin::ROUTE_MY_GROUPS);
        }

        $defaultSort = $GroupModel::getAllowedSorts()['new']['orderBy'];
        $where = $filters[0]['where'];
        $GroupData = $GroupModel->getMyGroups($where, $defaultSort, $Limit, $Offset);
        $CountGroups = $GroupModel->countMyGroups($where);
        $this->setData('CountGroups', $CountGroups);
        $this->setData('Groups', $GroupData, true);
        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', '/groups/mine/{Page}');
        }
        $queryString = GroupModel::getSortFilterQueryString($GroupModel->getSort(), $GroupModel->getFilters());
        $this->setData('_PagerUrl', $this->data('_PagerUrl').$queryString);
        $this->Pager = $PagerFactory->getPager($this->EventArguments['PagerType'], $this);
        $this->Pager->ClientID = 'Pager';
        $this->Pager->configure(
            $Offset,
            $Limit,
            $this->data('CountGroups'),
            $this->data('_PagerUrl')
        );

        PagerModule::current($this->Pager);

        $this->setData('_Page', $Page);
        $this->setData('_Limit', $Limit);

        if($filter == 'regular') {
            $title = 'My Groups';
            $noDataText = 'No groups were found.';
            $this->setData('Breadcrumbs', [
                ['Name' => 'Groups', 'Url' =>  '/groups/'.$queryString],
                ['Name' => $title, 'Url' =>  '/groups/mine/'.$queryString]]);
        } else if($filter == 'challenge'){
            $title = 'My Challenges';
            $noDataText = 'No challenges were found.';
            $this->setData('Breadcrumbs', [
                ['Name' => 'Challenges', 'Url' =>  '/groups/'.$queryString],
                ['Name' => $title, 'Url' =>  '/groups/mine/'.$queryString]]);

        }
        $this->setData('Title', $title);
        $this->setData('NoDataText',$noDataText);

        $this->View = 'list';

        $this->render();
    }

    public function all($Page = false, $filter = '') {
        // Setup head
        Gdn_Theme::section('GroupList');

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);
        $GroupModel = new GroupModel();
        $GroupModel->setFilters(Gdn::request()->get());
        $filters =  $GroupModel->getFiltersFromKeys($GroupModel->getFilters());

        // Filter wasn't found.
        // TODO: redirect to a default page
        if(count($filters) == 0) {
            redirectTo(GroupsPlugin::ROUTE_CHALLENGE_GROUPS);
        }

        $defaultSort = $GroupModel::getAllowedSorts()['new']['orderBy'];
        $where = $filters[0]['where'];
        $GroupData = $GroupModel->getAvailableGroups($where, $defaultSort, $Limit, $Offset);
        $CountGroups = $GroupModel->countAvailableGroups($where);
        $this->setData('CountGroups', $CountGroups);
        $this->setData('Groups', $GroupData, true);
        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', '/groups/all/{Page}');
        }
        $queryString = GroupModel::getSortFilterQueryString($GroupModel->getSort(), $GroupModel->getFilters());
        $this->setData('_PagerUrl', $this->data('_PagerUrl').$queryString);
        $this->Pager = $PagerFactory->getPager($this->EventArguments['PagerType'], $this);
        $this->Pager->ClientID = 'Pager';
        $this->Pager->configure(
            $Offset,
            $Limit,
            $this->data('CountGroups'),
            $this->data('_PagerUrl')
        );

        PagerModule::current($this->Pager);

        $this->setData('_Page', $Page);
        $this->setData('_Limit', $Limit);

        if($filter == 'regular') {
            $title = 'Available Groups';
            $noDataText = 'No groups were found.';
            $this->setData('Breadcrumbs', [
                ['Name' => 'Groups', 'Url' =>  '/groups/'.$queryString],
                ['Name' => $title, 'Url' =>  '/groups/all/'.$queryString]]);

        } else if($filter == 'challenge'){
            $title = 'Available Challenges';
            $noDataText = 'No challenges were found.';
            $this->setData('Breadcrumbs', [
                ['Name' => 'Challenges', 'Url' =>  '/groups/'.$queryString],
                ['Name' => $title, 'Url' =>  '/groups/all/'.$queryString]]);

        }
        $this->setData('Title', $title);
        $this->setData('NoDataText',$noDataText);

        $this->View = 'list';

        $this->render();
    }
}
