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

        $this->fireEvent('AfterInitialize');
    }

    public function index() {
        // Setup head
        Gdn_Theme::section('GroupList');

        $this->title(t('Challenges'));
        $this->setData('Breadcrumbs', [['Name' => 'Challenges', 'Url' => GroupsPlugin::GROUPS_ROUTE]]);

        $GroupModel = new GroupModel();

        $this->GroupData = $GroupModel->getMyGroups(false, '', false,  0);
        $this->AvailableGroupData = $GroupModel->getAvailableGroups(false, '', false,  0);

        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));
        $this->setData('Groups', $this->GroupData, true);
        $this->setData('AvailableGroups', $this->AvailableGroupData, true);

        $this->render();
    }

    public function mine($Page = false) {
        // Setup head
        $this->allowJSONP(true);
        Gdn_Theme::section('GroupList');

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);

        // Allow page manipulation
        $this->EventArguments['Page'] = &$Page;
        $this->EventArguments['Offset'] = &$Offset;
        $this->EventArguments['Limit'] = &$Limit;
        $this->fireEvent('AfterPageCalculation');

        // Set canonical URL
        $this->canonicalUrl(url(concatSep('/', '/groups/mine', pageNumber($Offset, $Limit, true, false)), true));

        $this->title(t('My Challenges'));
        $this->setData('Breadcrumbs', [['Name' => t('Challenges'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => t('My Challenges'), 'Url' => GroupsPlugin::GROUPS_ROUTE.'/mine']]);

        $GroupModel = new GroupModel();

        $where = false;
        $this->GroupData = $GroupModel->getMyGroups($where, '', $Limit, $Offset);

        $CountGroups = $GroupModel->countMyGroups($where);
        $this->setData('CountGroups', $CountGroups);
        $this->setData('Groups', $this->GroupData, true);
        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));
        $this->setJson('Loading', $Offset.' to '.$Limit);

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        $this->EventArguments['PagerType'] = 'Pager';
        $this->fireEvent('BeforeBuildPager');
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', '/groups/mine/{Page}');
        }
        $queryString = '';// DiscussionModel::getSortFilterQueryString($DiscussionModel->getSort(), $DiscussionModel->getFilters());
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
        $this->fireEvent('AfterBuildPager');

        $this->View = 'list';

        // Deliver JSON data if necessary
        if ($this->_DeliveryType != DELIVERY_TYPE_ALL) {
            $this->setJson('LessRow', $this->Pager->toString('less'));
            $this->setJson('MoreRow', $this->Pager->toString('more'));
            $this->View = 'groups';
        }

        $this->render();
    }

    public function all($Page = false) {
        // Setup head
        $this->allowJSONP(true);
        Gdn_Theme::section('GroupList');

        // Determine offset from $Page
        list($Offset, $Limit) = offsetLimit($Page, c('Vanilla.Groups.PerPage', 30), true);
        $Page = pageNumber($Offset, $Limit);

        // Allow page manipulation
        $this->EventArguments['Page'] = &$Page;
        $this->EventArguments['Offset'] = &$Offset;
        $this->EventArguments['Limit'] = &$Limit;
        $this->fireEvent('AfterPageCalculation');

        // Set canonical URL
        $this->canonicalUrl(url(concatSep('/', '/groups/all', pageNumber($Offset, $Limit, true, false)), true));

        $this->title(t('Available Challenges'));
        $this->setData('Breadcrumbs', [['Name' => t('Challenges'), 'Url' => GroupsPlugin::GROUPS_ROUTE],
            ['Name' => t('Available Challenges'), 'Url' => GroupsPlugin::GROUPS_ROUTE.'/all']]);

        $GroupModel = new GroupModel();

        $where = false;
        $this->GroupData = $GroupModel->getAvailableGroups($where, '', $Limit, $Offset);

        $CountGroups = $GroupModel->countAvailableGroups($where);
        $this->setData('CountGroups', $CountGroups);
        $this->setData('Groups', $this->GroupData, true);
        $this->setData('CurrentUserGroups', $GroupModel->memberOf(Gdn::session()->UserID));
        $this->setJson('Loading', $Offset.' to '.$Limit);

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        $this->EventArguments['PagerType'] = 'Pager';
        $this->fireEvent('BeforeBuildPager');
        if (!$this->data('_PagerUrl')) {
            $this->setData('_PagerUrl', '/groups/all/{Page}');
        }
        $queryString = '';
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
        $this->fireEvent('AfterBuildPager');

        $this->View = 'list';

        // Deliver JSON data if necessary
        if ($this->_DeliveryType != DELIVERY_TYPE_ALL) {
            $this->setJson('LessRow', $this->Pager->toString('less'));
            $this->setJson('MoreRow', $this->Pager->toString('more'));
            $this->View = 'groups';
        }

        $this->render();
    }
}
