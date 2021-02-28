<?php
/**
 * Class GroupModel
 */
class GroupModel extends Gdn_Model {
    use StaticInitializer;

    //
    // Group Privacy
    //
    /** Slug for PUBLIC privacy. */
    const PRIVACY_PUBLIC = 'public';

    /** Slug for PRIVATE privacy. */
    const PRIVACY_PRIVATE = 'private';

    /** Slug for SECRET privacy. */
    const PRIVACY_SECRET = 'secret';

    //
    // Group Roles
    //
    const ROLE_MEMBER = 'member';
    const ROLE_LEADER = 'leader';

    //
    // Group Types
    //
    const TYPE_CHALLENGE = 'challenge';
    const TYPE_REGULAR = 'regular';

    /** @var string The filter key for clearing-type filters. */
    const EMPTY_FILTER_KEY = 'none';

    /** @var string Default column to order by. */
    const DEFAULT_SORT_KEY = 'new';

    /**
     * @var array The filters that are accessible via GET. Each filter corresponds with a where clause. You can have multiple
     * filter sets. Every filter must be added to a filter set.
     *
     * Each filter set has the following properties:
     * - **key**: string - The key name of the filter set. Appears in the query string, should be url-friendly.
     * - **name**: string - The display name of the filter set. Usually appears in the UI.
     * - **filters**: array - The filters in the set.
     *
     * Each filter in the array has the following properties:
     * - **key**: string - The key name of the filter. Appears in the query string, should be url-friendly.
     * - **setKey**: string - The key name of the filter set.
     * - **name**: string - The display name of the filter. Usually appears as an option in the UI.
     * - **where**: string - The where array query to execute for the filter. Uses
     */
    // [$setKey]['filters'][$key] = ['key' => $key, 'setKey' => $setKey, 'name' => $name, 'wheres' => $wheres];
    protected static $allowedFilters = [
      'filter' =>  [ 'key' => 'filter', 'name' => 'All',
          'filters' => [
                    'challenge' => ['key' => 'challenge', 'name' => 'Challenges', 'where' => ['g.Type' => self::TYPE_CHALLENGE]],
                    'regular' =>['key' => 'regular', 'name' => 'Groups', 'where' => ['g.Type' => self::TYPE_REGULAR]]
                    ]

        ],
    ];

    /**
     * @var array The sorts that are accessible via GET. Each sort corresponds with an order by clause.
     *
     * Each sort in the array has the following properties:
     * - **key**: string - The key name of the sort. Appears in the query string, should be url-friendly.
     * - **name**: string - The display name of the sort.
     * - **orderBy**: string - An array indicating order by fields and their directions in the format:
     *   `['field1' => 'direction', 'field2' => 'direction']`
     */
    protected static $allowedSorts = [
        'new' => ['key' => 'new', 'name' => 'New', 'orderBy' => ['g.DateInserted' => 'desc']],
        'old' => ['key' => 'new', 'name' => 'Old', 'orderBy' => ['g.DateInserted' => 'asc']]
    ];

    /**
     * @var array The filter keys of the wheres we apply in the query.
     */
    protected $filters = [
    ];

    /**
     * @var string The sort key of the order by we apply in the query.
     */
    protected $sort = '';

    /**
     * @var GroupModel $instance;
     */
    private static $instance;

    private $currentUserTopcoderProjectRoles = [];

    /**
     * Class constructor. Defines the related database table name.
     */
    public function __construct() {
        parent::__construct('Group');
        $this->fireEvent('Init');
    }

    /**
     * The shared instance of this object.
     *
     * @return GroupModel Returns the instance.
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new GroupModel();
        }
        return self::$instance;
    }

    /**
     * @return array The current sort array.
     */
    public static function getAllowedSorts() {
        self::initStatic();
        return self::$allowedSorts;
    }

    /**
     * Get the registered filters.
     *
     * This method must never be called before plugins initialisation.
     *
     * @return array The current filter array.
     */
    public static function getAllowedFilters() {
        self::initStatic();
        return self::$allowedFilters;
    }

    /**
     * @return array
     */
    public function getFilters() {
        return $this->filters;
    }

    /**
     * @return string
     */
    public function getSort() {
        return $this->sort;
    }

    /**
     * Set the discussion sort.
     *
     * This setter also accepts an array and checks if the sort key exists on the array. Will only set the sort property
     * if it exists in the allowed sorts array.
     *
     * @param string|array $sort The prospective sort to set.
     */
    public function setSort($sort) {
        if (is_array($sort)) {
            $safeSort = $this->getSortFromArray($sort);
            $this->sort = $safeSort;
        } elseif (is_string($sort)) {
            $safeSort = $this->getSortFromString($sort);
            $this->sort = $safeSort;
        }
    }

    /**
     * Will only set the filters property if the passed filters exist in the allowed filters array.
     *
     * @param array $filters The prospective filters to set.
     */
    public function setFilters($filters) {
        if (is_array($filters)) {
            $safeFilters = $this->getFiltersFromArray($filters);
            $this->filters = $safeFilters;
        } elseif (is_string($filters)) {
            $safeFilters = $this->getFiltersFromArray([$filters]);
            $this->filters = $safeFilters;
        }
    }

    /**
     * @return string
     */
    public static function getDefaultSort() {
        // Try to find a matching sort.
        foreach (self::getAllowedSorts() as $sort) {
            if (val('key', $sort, []) == self::DEFAULT_SORT_KEY) {
                return $sort;
            }
        }

        Logger::log(
            Logger::DEBUG,
            'Sort: default Sort Key does not exist in the GroupModel\'s allowed sorts array.',
            ['sortKey' => self::DEFAULT_SORT_KEY]
        );

        return [];
    }

    /**
     * Retrieves valid set key and filter keys pairs from an array, and returns the setKey => filterKey values.
     *
     * Works real well with unfiltered request arguments. (i.e., Gdn::request()->get()) Will only return safe
     * set key and filter key pairs from the filters array or an empty array if not found.
     *
     * @param array $array The array to get the filters from.
     * @return array The valid filters from the passed array or an empty array.
     */
    protected function getFiltersFromArray($array) {
        $filterKeys = [];
        foreach (self::getAllowedFilters() as $filterSet) {
            $filterSetKey = val('key', $filterSet);
            // Check if any of our filters are in the array. Filter key value is unsafe.
            if ($filterKey = val($filterSetKey, $array)) {
                // Check that value is in filter array to ensure safety.
                if (val($filterKey, val('filters', $filterSet))) {
                    // Value is safe.
                    $filterKeys[$filterSetKey] = $filterKey;
                } else {
                    Logger::log(
                        Logger::NOTICE,
                        'Filter: {filterSetKey} => {$filterKey} does not exist in the GroupModel\'s allowed filters array.',
                        ['filterSetKey' => $filterSetKey, 'filterKey' => $filterKey]
                    );
                }
            }
        }
        return $filterKeys;
    }

    /**
     * Retrieves the sort key from an array and if the value is valid, returns it.
     *
     * Works real well with unfiltered request arguments. (i.e., Gdn::request()->get()) Will only return a safe sort key
     * from the sort array or an empty string if not found.
     *
     * @param array $array The array to get the sort from.
     * @return string The valid sort from the passed array or an empty string.
     */
    protected function getSortFromArray($array) {
        $unsafeSortKey = val('sort', $array);
        foreach (self::getAllowedSorts() as $sort) {
            if ($unsafeSortKey == val('key', $sort)) {
                // Sort key is valid.
                return val('key', $sort);
            }
        }
        if ($unsafeSortKey) {
            Logger::log(
                Logger::NOTICE,
                'Sort: {unsafeSortKey} does not exist in the DiscussionModel\'s allowed sorts array.',
                ['unsafeSortKey' => $unsafeSortKey]
            );
        }
        return '';
    }

    /**
     * Checks the allowed sorts array for the string and it is valid, returns it the string.
     *
     * If not, returns an empty string. Will only return a safe sort key from the sort array or an empty string if not
     * found.
     *
     * @param string $string The string to get the sort from.
     * @return string A valid sort key or an empty string.
     */
    protected function getSortFromString($string) {
        if (val($string, self::$allowedSorts)) {
            // Sort key is valid.
            return $string;
        } else {
            Logger::log(
                Logger::DEBUG,
                'Sort "{sort}" does not exist in the GroupModel\'s allowed sorts array.',
                ['sort' => $string]
            );
            return '';
        }
    }

    /**
     * Takes a collection of filters and returns the corresponding filter key/value array [setKey => filterKey].
     *
     * @param array $filters The filters to get the keys for.
     * @return array The filter key array.
     */
    protected function getKeysFromFilters($filters) {
        $filterKeyValues = [];
        foreach ($filters as $filter) {
            if (isset($filter['setKey']) && isset($filter['key'])) {
                $filterKeyValues[val('setKey', $filter)] = val('key', $filter);
            }
        }
        return $filterKeyValues;
    }


    /**
     * Takes an array of filter key/values [setKey => filterKey] and returns a collection of filters.
     *
     * @param array $filterKeyValues The filters key array to get the filter for.
     * @return array An array of filters.
     */
    public function getFiltersFromKeys($filterKeyValues) {
        $filters = [];
        $allFilters = self::getAllowedFilters();
        foreach ($filterKeyValues as $key => $value) {
            if (isset($allFilters[$key]['filters'][$value])) {
                $filters[] = $allFilters[$key]['filters'][$value];
            }
        }
        return $filters;
    }

    /**
     * @param string $sortKey
     * @return array
     */
    public function getSortFromKey($sortKey) {
        return val($sortKey, self::getAllowedSorts(), []);
    }

    /**
     * Get the current sort/filter query string.
     *
     * You can pass no parameters or pass either a new filter key or sort key to build a new query string, leaving the
     * other properties intact.
     *
     * @param string $selectedSort
     * @param array $selectedFilters
     * @param string $sortKeyToSet The key name of the sort in the sorts array.
     * @param array $filterKeysToSet An array of filters, where the key is the key of the filterSet in the filters array
     * and the value is the key of the filter.
     * @return string The current or amended query string for sort and filter.
     */
    public static function getSortFilterQueryString($selectedSort, $selectedFilters, $sortKeyToSet = '', $filterKeysToSet = []) {
        $filterString = '';
        $filterKeys = array_merge($selectedFilters, $filterKeysToSet);

        // Build the sort query string
        foreach ($filterKeys as $setKey => $filterKey) {
            // If the preference is none, don't show it.
            if ($filterKey != self::EMPTY_FILTER_KEY) {
                if (!empty($filterString)) {
                    $filterString .= '&';
                }
                $filterString .= $setKey.'='.$filterKey;
            }
        }

        $sortString = '';
        if (!$sortKeyToSet) {
            $sort = $selectedSort;
            if ($sort) {
                $sortString = 'sort='.$sort;
            }
        } else {
            $sortString = 'sort='.$sortKeyToSet;
        }

        $queryString = '';
        if (!empty($sortString) && !empty($filterString)) {
            $queryString = '?'.$sortString.'&'.$filterString;
        } elseif (!empty($sortString)) {
            $queryString = '?'.$sortString;
        } elseif (!empty($filterString)) {
            $queryString = '?'.$filterString;
        }

        return $queryString;
    }

    /**
     * Add a sort to the allowed sorts array.
     *
     * @param string $key The key name of the sort. Appears in the query string, should be url-friendly.
     * @param string $name The display name of the sort.
     * @param string|array $orderBy An array indicating order by fields and their directions in the format:
     *      array('field1' => 'direction', 'field2' => 'direction')
     */
    public static function addSort($key, $name, $orderBy) {
        self::$allowedSorts[$key] = ['key' => $key, 'name' => $name, 'orderBy' => $orderBy];
    }

    /**
     * Add a filter to the allowed filters array.
     *
     * @param string $key The key name of the filter. Appears in the query string, should be url-friendly.
     * @param string $name The display name of the filter. Usually appears as an option in the UI.
     * @param array $wheres The where array query to execute for the filter. Uses
     * @param string $setKey The key name of the filter set.
     */
    public static function addFilter($key, $name, $wheres, $setKey = 'filter') {
        if (!val($setKey, self::getAllowedFilters())) {
            self::addFilterSet($setKey);
        }
        self::$allowedFilters[$setKey]['filters'][$key] = ['key' => $key, 'setKey' => $setKey, 'name' => $name, 'wheres' => $wheres];
    }

    /**
     * Adds a filter set to the allowed filters array.
     *
     * @param string $setKey The key name of the filter set.
     * @param string $setName The name of the filter set. Appears in the UI.
     * @param array $categoryIDs The IDs of the categories that this filter will work on. If empty, filter is global.
     */
    public static function addFilterSet($setKey, $setName = '', $categoryIDs = []) {
        if (!$setName) {
            $setName = t('All Groups');
        }
        self::$allowedFilters[$setKey]['key'] = $setKey;
        self::$allowedFilters[$setKey]['name'] = $setName;
       // self::$allowedFilters[$setKey]['categories'] = $categoryIDs;

        // Add a way to let users clear any filters they've added.
        self::addClearFilter($setKey, $setName);
    }

    /**
     * Removes a filter set from the allowed filter array with the passed set key.
     *
     * @param string $setKey The key of the filter to remove.
     */
    public static function removeFilterSet($setKey) {
        if (val($setKey, self::$allowedFilters)) {
            unset(self::$allowedFilters[$setKey]);
        }
    }


    /**
     * Removes a filters from the allowed filter array with the passed filter key/values.
     *
     * @param array $filterKeys The key/value pairs of the filters to remove.
     */
    public static function removeFilter($filterKeys) {
        foreach ($filterKeys as $setKey => $filterKey) {
            if (isset(self::$allowedFilters[$setKey]['filters'][$filterKey])) {
                unset(self::$allowedFilters[$setKey]['filters'][$filterKey]);
            }
        }
    }

    /**
     * Adds an option to a filter set filters array to clear any existing filters on the data.
     *
     * @param string $setKey The key name of the filter set to add the option to.
     * @param string $setName The display name of the option. Usually the human-readable set name.
     */
    protected static function addClearFilter($setKey, $setName = '') {
        self::$allowedFilters[$setKey]['filters'][self::EMPTY_FILTER_KEY] = [
            'key' => self::EMPTY_FILTER_KEY,
            'setKey' => $setKey,
            'name' => $setName,
            'wheres' => []
        ];
    }

    /**
     * If you don't want to use any of the default sorts, use this little buddy.
     */
    public static function clearSorts() {
        self::$allowedSorts = [];
    }

    /**
     * Removes a sort from the allowed sort array with the passed key.
     *
     * @param string $key The key of the sort to remove.
     */
    public static function removeSort($key) {
        if (val($key, self::$allowedSorts)) {
            unset(self::$allowedSorts[$key]);
        }
    }

   //  public function setCurrentUserTopcoderProjectRoles($topcoderProjectRoles = []){
   //      $this->currentUserTopcoderProjectRoles = $topcoderProjectRoles;
   // }

    /**
     * Clear the groups cache.
     */
    public function clearCache() {
        $key = 'Groups';
        Gdn::cache()->remove($key);
    }

    /**
     * Get max pages
     * @return int
     */
    public function getMaxPages() {
        return (int)c('Vanilla.Groups.MaxPages')? : 100;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultLimit() {
        return c('Vanilla.Groups.PerPage', 30);
    }

    /**
     * Get group ID from a discussion
     * @param $discussion
     * @return false|mixed
     */
    public function findGroupIDFromDiscussion($discussion){
        if(is_numeric($discussion)){
            $discussionModel = new DiscussionModel();
            $discussion = $discussionModel->getID($discussion);
        }
        $categoryID = val('CategoryID', $discussion);
        $category = CategoryModel::categories($categoryID);
        return val('GroupID', $category, false);
    }

    /**
     * Get all group categories by GroupID
     *
     * @param $groupID
     * @return array|false|null
     */
    public function getAllGroupCategoryIDs($groupID) {
        $categoryModel = new CategoryModel();
        $categories = $categoryModel->getWhere(['GroupID' => $groupID])->resultArray();
        $categoryIDs = array_column($categories,'CategoryID');
        return $categoryIDs;
    }

    /**
     * Default Gdn_Model::get() behavior.
     *
     * Prior to 2.0.18 it incorrectly behaved like GetID.
     * This method can be deleted entirely once it's been deprecated long enough.
     *
     * @return object DataSet
     */
    public function get($orderFields = '', $orderDirection = 'asc', $limit = false, $offset = false) {
        return parent::get($orderFields, $orderDirection, $limit, $offset);
    }

    /**
     * Get the list of public group IDs
     *
     */
    public function getAllPublicGroupIDs(){
        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('g.GroupID')
            ->from('Group g')
            ->where('g.Privacy' , [GroupModel::PRIVACY_PUBLIC] );

        $data = $sql->get()->resultArray();
        return array_column($data, 'GroupID');
    }


    /**
     * Get the list of groups as categories for the current user
     *
     */
    public function getAllGroupsAsCategoryIDs(){
        $sql = $this->SQL;
        $sql->select('c.*')
            ->from('Category c')
            ->leftJoin('Group g', 'c.UrlCode = g.ChallengeID')
            ->leftJoin('UserGroup ug', 'ug.GroupID = g.GroupID')
            ->where('ug.UserID' , Gdn::session()->UserID );

        $data = $sql->get()->resultArray();
        return array_column($data, 'CategoryID');
    }

    /**
     * Get all availbale categories for users based on group membership
     * @return array
     */
    public function getAllAvailableCategories() {
        $userGroupCategoryIDs =  $this->getAllGroupsAsCategoryIDs();
        $ancestors = $this->getAncestors($userGroupCategoryIDs);
        $ancestorIDs = array_column($ancestors, 'CategoryID');
        return array_unique(array_values(array_merge($ancestorIDs, $userGroupCategoryIDs)));
    }

    /**
     * Challenge Forums
     * @return object
     */
    public function getChallengesForums() {
        $categoryModel  = new CategoryModel();
         return $categoryModel->getByCode('challenges-forums');
    }

    public function checkGroupCategoryPermissions($categoryTree) {
        GroupsPlugin::log('checkGroupCategoryPermissions', []);
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return $categoryTree;
        }

        $categoriesWithGroupPermissions = $this->getAllAvailableCategories();
        $userGroupCategoryIDs =  $this->getAllGroupsAsCategoryIDs();
        $categoryModel = new CategoryModel();
        $challengesForumsCategory = $this->getChallengesForums();
        $challengesForumsCategoryID = val('CategoryID',$challengesForumsCategory);
        foreach ($categoryTree as &$category) {
            $categoryID = $category['CategoryID'];

            $loadedCategory =  $categoryModel->getID($categoryID, DATASET_TYPE_ARRAY);
            // CategoriesController has an invalid category'depth
            $depth = $loadedCategory['Depth'];
            $parentCategoryID =  $category['ParentCategoryID'];

            if($depth < 2) {
                if($challengesForumsCategoryID == $categoryID) {
                    $category['isDisplayed'] = count($categoriesWithGroupPermissions) > 0;
                } else {
                    $category['isDisplayed'] = true;
                }
            } else {
                $category['isDisplayed'] = false;
                if ($depth  == 2 && $parentCategoryID != $challengesForumsCategoryID) {
                    $category['isDisplayed'] = true;
                } else if ($depth  >= 2 && (in_array($categoryID, $categoriesWithGroupPermissions)
                        || in_array($parentCategoryID, $userGroupCategoryIDs)
                    )) {
                    $category['isDisplayed'] = true;
                }
            }
        }

        return array_filter($categoryTree, function($e) {
            return ($e['isDisplayed'] == true);
        });
    }


    /**
     * List of Categories
     * @param $categoryIDs
     * @return bool|Gdn_DataSet
     */
    public function getAncestors($categoryIDs) {
        return $this->SQL
            ->select('c.ParentCategoryID, c.CategoryID, c.TreeLeft, c.TreeRight, c.Depth, c.Name, c.Description, c.CountDiscussions, c.CountComments, c.AllowDiscussions, c.UrlCode')
            ->from('Category c')
            ->join('Category d', 'c.TreeLeft < d.TreeLeft and c.TreeRight > d.TreeRight')
            ->where('d.CategoryID', $categoryIDs)
            ->orderBy('c.TreeLeft', 'asc')
            ->get()
            ->resultArray();
    }

    /**
     * Get all available groups including private ones
     */
    public function getAvailableGroups($where =[], $orderFields = '', $limit = false, $offset = false) {

        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getAvailableGroups()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        $groupTypes = [GroupModel::PRIVACY_PUBLIC, GroupModel::PRIVACY_PRIVATE];
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            array_push($groupTypes,GroupModel::PRIVACY_SECRET);
        }

        // Build up the base query. Self-join for optimization.
        $sql->select('g.*')
            ->from('Group g')
            ->leftjoin('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('ug.UserID' , null)
            ->where('g.Privacy' , $groupTypes )
            ->where('g.Archived' , 0 )
            ->where($where)
            ->limit($limit, $offset);

        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($field, $direction);
        }

        $data = $sql->get();
        return $data;
    }

    /**
     * Get count of available groups
     */
    public function countAvailableGroups($where =[]) {

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        $groupTypes = [GroupModel::PRIVACY_PUBLIC, GroupModel::PRIVACY_PRIVATE];
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            array_push($groupTypes,GroupModel::PRIVACY_SECRET);
        }

        // Build up the base query. Self-join for optimization.
        $sql->select('count(*) Count')
            ->from('Group g')
            ->leftjoin('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('ug.UserID' , null)
            ->where('g.Privacy' , $groupTypes)
            ->where('g.Archived' , 0 )
            ->where($where);

        $data = $sql->get()
            ->firstRow();
        GroupsPlugin::log('countAvailableGroups', ['data' => $data]);
        return $data === false ? 0 : $data->Count;
    }

   /**
    * Get the list of groups for the current user
    *
    */
    public function getMyGroups($where =[], $orderFields = '', $limit = false, $offset = false) {
        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getLeaders()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('g.*, ug.Role, ug.DateInserted')
            ->from('Group g')
            ->join('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('g.Archived' , 0 )
            ->where($where);


        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($field, $direction);
        }

        $sql->limit($limit, $offset);


        $data = $sql->get();
        return $data;
    }


    /**
     * Get count of the groups fir the current user
     */
    public function countMyGroups($where =[]) {
        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('count(*) Count')
            ->from('Group g')
            ->join('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('g.Archived' , 0 )
            ->where($where);


        $data = $sql->get()
            ->firstRow();

        return $data === false ? 0 : $data->Count;
    }


    /**
     * Checks the user's permissions for the specified permission.
     *
     * Returns a boolean value indicating if the action is permitted.
     *
     * @param $userID
     * @param $groupID
     * @param null $categoryID
     * @param null $permissionCategoryID
     * @param null $permissions
     * @param bool $fullMatch If $Permission is an array, $FullMatch indicates if all permissions specified are required.
     * If false, the user only needs one of the specified permissions.
     * @return boolean Returns **true** if the user has permission or **false** otherwise.
     */
    public static function checkPermission($userID,$groupID,$categoryID = null, $permissionCategoryID = null, $permissions = null, $fullMatch = true, $groupIDs = []){
        if($userID === Gdn::session()->UserID) {
            $userPermissions = Gdn::session()->getPermissions();
        } else {
            $userPermissions = Gdn::userModel()->getPermissions($userID);
        }

        // Check access to a category
        $result = false;
        if(GroupModel::isMemberOfGroup($userID,$groupID)) {
            if ($permissions == null) {
                $result = true;
            } else {
                $result = PermissionModel::checkPermission($userPermissions,$permissions, $fullMatch, 'Category', $permissionCategoryID)
                    || PermissionModel::checkPermission($userPermissions,$permissions, $fullMatch, 'Category', $categoryID);
            }
        } else {
            // User is not a group member, checking admin group permissions
            if (PermissionModel::checkPermission($userPermissions,[
                    GroupsPlugin::GROUPS_GROUP_ADD_PERMISSION,
                    GroupsPlugin::GROUPS_CATEGORY_MANAGE_PERMISSION,
                    GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION,
                    GroupsPlugin::GROUPS_EMAIL_INVITATIONS_PERMISSION
                ], false)) {
                $result = PermissionModel::checkPermission($userPermissions,$permissions, $fullMatch, 'Category', $permissionCategoryID) ||
                PermissionModel::checkPermission($userPermissions, $permissions, $fullMatch, 'Category', $categoryID);
            }
        }
        return $result;
    }

    /**
     * Join a new member.
     * @param $GroupID
     * @param $UserID
     * @param bool $watched
     * @param bool $followed
     */
    public function join($GroupID, $UserID, $watched = true, $followed = true ){
        $Fields = ['Role' => GroupModel::ROLE_MEMBER, 'GroupID' => $GroupID,'UserID' => $UserID, 'DateInserted' => Gdn_Format::toDateTime()];
        if( $this->SQL->getWhere('UserGroup', ['GroupID' => $GroupID,'UserID' => $UserID])->numRows() == 0) {
            $this->SQL->insert('UserGroup', $Fields);
            $this->notifyJoinGroup($GroupID, $UserID);
        }
        $this->followGroup($GroupID, $UserID, $followed);
        $this->watchGroup($GroupID, $UserID, $watched);
        self::clearUserGroupCache($UserID);
        $discussionModel = new DiscussionModel();
        $discussionModel->updateUserDiscussionCount($UserID, false);
    }

    /**
     * Invite a new member
     * @param $GroupID
     * @param $UserID
     * @return bool|Gdn_DataSet|object|string
     */
    public function invite($GroupID, $UserID){
        $this->sendInviteEmail($GroupID, $UserID);
    }

    /**
     * Accept an invitation
     * @param $GroupID
     * @param $UserID
     * @return bool|Gdn_DataSet|object|string
     */
    public function accept($groupID, $userID){
        $this->join($groupID, $userID);
    }

    /**
     * Return true if user is a member of the group
     * @param $userID
     * @param $groupID
     * @return bool
     */
    public static function isMemberOfGroup($userID, $groupID) {
        $groups = self::memberOf($userID);
        return array_key_exists($groupID, $groups);
    }

    /**
     * Set a new role for a member
     * @param $GroupID
     * @param $MemberID
     * @param $Role
     * @return bool|Gdn_DataSet|object|string
     * @throws Exception
     */
    public function setRole($GroupID, $MemberID, $Role){
        self::clearUserGroupCache($MemberID);
        return $this->SQL->update('UserGroup')
            ->set('Role' , $Role)
            ->where('GroupID' , $GroupID)
            ->where('UserID', $MemberID)
            ->put();
    }

    /**
     * Remove a member from group
     *
     * @param $GroupID
     * @param $MemberID
     * @return bool|Gdn_DataSet|object|string|void
     */
    public function removeMember($GroupID, $MemberID){
        $this->unbookmarkGroupDiscussions($GroupID, $MemberID);
        $this->unwatchGroup($GroupID, $MemberID);
        $this->unfollowGroup($GroupID, $MemberID);
        $result = $this->SQL->delete('UserGroup', ['GroupID' => $GroupID, 'UserID' => $MemberID]);
        self::clearUserGroupCache($MemberID);
        $discussionModel = new DiscussionModel();
        $discussionModel->updateUserDiscussionCount($MemberID, false);
        return $result;

    }

    private function unbookmarkGroupDiscussions($GroupID, $MemberID) {
        $this->SQL->update(
                'UserDiscussion ud',
                ['Bookmarked'=> 0]
            )->leftJoin('Discussion d', 'ud.DiscussionID = d.DiscussionID')
             ->leftJoin('Category c', 'd.CategoryID = c.CategoryID')
             ->where('ud.UserID', $MemberID)
             ->where('c.GroupID', $GroupID)->put();
        // Update the user's bookmark count
        $discussionModel = new DiscussionModel();
        $discussionModel->setUserBookmarkCount($MemberID);
    }

    /**
     * Get leaders
     *
     * @return object DataSet
     */
    public function getLeaders($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        $where = array_merge(['Role' => GroupModel::ROLE_LEADER], $where);
        return $this->getUserGroups($groupID, $where, $orderFields, $limit , $offset);
    }

    /**
     * Get members
     *
     * @param $groupID
     * @param array $where
     * @param string $orderFields
     * @param bool $limit
     * @param bool $offset
     * @return object DataSet
     */
    public function getMembers($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        return $this->getUserGroups($groupID, $where, $orderFields , $limit, $offset);
    }

    private function getUserGroups($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getLeaders()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('u.*, ug.Role, ug.DateInserted')
            ->from('UserGroup ug')
            ->join('User u', 'ug.UserID = u.UserID and ug.GroupID='.$groupID)
            ->limit($limit, $offset);

        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($this->addFieldPrefix($field), $direction);
        }

        $sql->where($where);

        $data = $sql->get()->resultArray();;
        return $data;
    }

    /**
     * Returns a resultset of group data related to the specified GroupID.
     *
     * @param int The GroupID to filter to.
     */
    public function getByGroupID($groupID) {
        return $this->getWhere(['GroupID' => $groupID])->firstRow();
    }

    /**
     * Save group data.
     *
     * @param array $formPostValues The group row to save.
     * @param array|false $settings Additional settings for the save.
     * @return bool|mixed Returns the group ID or false on error.
     */
    public function save($formPostValues, $settings = false) {
        // Define the primary key in this model's table.
        $this->defineSchema();

        $groupID = val('GroupID', $formPostValues);
        $ownerID = val('OwnerID', $formPostValues);
        $type = val('Type', $formPostValues);
        $name = val('Name', $formPostValues);
        $archived = val('Archived', $formPostValues);
        $insert = $groupID > 0 ? false : true;

        if ($insert) {
            // Figure out the next group ID.
            $maxGroupID = $this->SQL->select('g.GroupID', 'MAX')->from('Group g')->get()->value('GroupID', 0);
            $groupID = $maxGroupID + 1;
            $this->addInsertFields($formPostValues);
            $formPostValues['GroupID'] = strval($groupID); // string for validation
        } else {
            $this->addUpdateFields($formPostValues);
        }

        // TODO: Move creating Challenge group categories from challenge processor
        // Validate the form posted values
        if ($this->validate($formPostValues, $insert)) {
            $fields = $this->Validation->schemaValidationFields();
            $fields = $this->coerceData($fields);

            if ($insert === false) {
                $this->update($fields, ['GroupID' => $groupID]);
            } else {
                $this->insert($fields);
                //Create a category for a regular group
                if($type == GroupModel::TYPE_REGULAR) {
                    $categoryModel = new CategoryModel();
                    $parentCategory = $categoryModel->getByCode('groups');
                    $categoryData = [ 'Name' => $name,
                        'ParentCategoryID' => $parentCategory->CategoryID,
                        'DisplayAs' => 'Discussions',
                        'AllowFileUploads' => 1,
                        'UrlCode' => 'group-'.$groupID,
                        'GroupID' => $groupID,
                        'Archived' => $archived];
                    $categoryID = $categoryModel->save($categoryData);
                    // TODO
                    if(!$categoryID) {
                        // Error
                    }
                }
                $this->SQL->insert(
                    'UserGroup',
                    [
                        'UserID' => $ownerID,
                        'GroupID' => $groupID,
                        'Role' => GroupModel::ROLE_LEADER,
                        'DateInserted' => Gdn_Format::toDateTime()
                    ]
                );
                $this->notifyNewGroup($groupID, $fields['Name']);
            }

           if (Gdn::cache()->activeEnabled()) {
                // Don't update the user table if we are just using cached permissions.
                $this->clearCache();
            }
        } else {
            $groupID = false;
        }
        return $groupID;
    }

    /**
     * Delete a group.
     *
     * @param int $groupID The ID of the group to delete.
     * @param array $options An array of options to affect the behavior of the delete.
     *
     * @return bool Returns **true** on success or **false** otherwise.
     */
    public function deleteID($groupID, $options = []) {
        $this->SQL->delete('UserGroup', ['GroupID' => $groupID]);
        return $this->SQL->delete('Group', ['GroupID' => $groupID]);
    }

    /**
     * Validate a group
     * @inheritdoc
     */
    public function validate($values, $insert = false) {
        $result = true;
        // TODO: Move Challenge group validation from challenge processor
        $type = val('Type', $values);
        $urlCode = val('UrlCode', $values);
        if($insert === true) {
            $categoryModel = new CategoryModel();
            if($type === GroupModel::TYPE_REGULAR) {
                $category = $categoryModel->getByCode($urlCode);
                if($category) {
                    $result = false;
                    $this->Validation->addValidationResult('UrlCode', 'Group UrlCode has existed.');
                }
                $parentCategory = $categoryModel->getByCode('groups');
                if (!$parentCategory) {
                    $result = false;
                    $this->Validation->addValidationResult('ParentCategoryID', 'Groups category was not found.');
                }

            }
        }
        $result = $result && parent::validate($values, $insert);
        return $result;
    }

    /**
     * Get count of members
     * @param $groupId
     * @param null $role
     * @return mixed
     */
    public function countOfMembers($groupId, $role = null){
        $sql = $this->SQL;
        $where = ['GroupID' => $groupId];
        if($role) {
            $where['Role']= $role;
        }

        return $sql->getCount('UserGroup', $where);
    }

    /**
     * Get all groups for the specified user
     * @param $userID
     * @return array|mixed|null
     */
    public static function memberOf($userID){
        $key = 'UserGroup_'.$userID;
        $result = Gdn::cache()->get($key);
        if ($result === Gdn_Cache::CACHEOP_FAILURE || $result == false) {
            $sql = Gdn::sql();
            $sql = clone $sql;
            $sql->reset();
            $result = $sql->select('ug.Role, ug.GroupID')
                ->from('UserGroup ug')
                ->where('UserID', $userID)
                ->get()->result(DATASET_TYPE_ARRAY);
            $map = array();
            foreach ($result as $row) {
                $map[$row['GroupID']] = $row['Role'];
            }

            Gdn::cache()->store($key, $map);
            return $result;
        } else {
            return $result;
        }
    }

    /**
     * Get a group role
     * @param $userID
     * @param $groupID
     * @return mixed
     */
    public function getGroupRoleFor($userID, $groupID) {
        $groups = self::memberOf($userID);
        if($groups && array_key_exists($groupID, $groups)) {
         return $groups[$groupID];
        }
        return false;
    }

    /**
     * Check group view permission
     *
     */
    public function canView($group) {
        if($group->Privacy == self::PRIVACY_PUBLIC){
            return true;
        } else {
            $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
            if($groupRole ||  Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can follow group categories
     *
     */
    public function canFollowGroup($group) {
        if($group->ChallengeID) {
            $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
            return $groupRole;
        }

        return false;
    }

    /**
     * Check if a user has followed at least one group category
     * @param $group
     * @param null $userID if userID is not set, the current user is used
     * @return bool
     */
    public function hasFollowedGroup($group, $userID = null) {
        if(!$userID) {
            $userID = Gdn::session()->UserID;
        }

        $categories = Gdn::sql()->getWhere('Category', ['GroupID' => $group->GroupID, 'DisplayAs' => 'Discussions'])->resultArray();
        $categoryIDs = array_column($categories, 'CategoryID');
        $result = Gdn::sql()->getWhere('UserCategory', ['UserID' => $userID, 'Followed' => 1, 'CategoryID' => $categoryIDs])
            ->resultArray();

        return  count($result) > 0;
    }

    /**
     * Can watch group categories
     *
     */
    public function canWatchGroup($group) {
        if($group->ChallengeID) {
            $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
            return $groupRole;
        }

        return false;
    }

    /**
     * Check if an user has watched at least one group category
     * @param $group
     * @param null $userID if userID is not set then the current user is used
     * @return bool User has watched at least one group category
     */
    public function hasWatchedGroup($group, $userID = null) {
        if(!$userID) {
            $userID = Gdn::session()->UserID;
        }
       $categoryModel = new CategoryModel();
       $categories = Gdn::sql()->getWhere('Category', ['GroupID' => $group->GroupID, 'DisplayAs' => 'Discussions'])->resultArray();
       $categoryIDs = array_column($categories, 'CategoryID');
       return $categoryModel->hasWatched($categoryIDs,$userID);
    }

    /**
     * Follow all group's categories
     * @param $group
     * @param $userID
     * @param bool $followed
     */
    public function followGroup($group, $userID, $followed = true ) {
        if(boolval(c('Vanilla.EnableCategoryFollowing'))) {
            if (is_numeric($group) && $group > 0) {
                $group = $this->getByGroupID($group);
            }

            $categories = Gdn::sql()->getWhere('Category', ['GroupID' => $group->GroupID, 'DisplayAs' => 'Discussions'])->resultArray();
            $categoryIDs = array_column($categories, 'CategoryID');

            foreach ($categoryIDs as $categoryID) {
                $this->SQL->replace(
                    'UserCategory',
                    ['Followed' => (int)$followed],
                    ['UserID' => $userID, 'CategoryID' => $categoryID]
                );
            }
            CategoryModel::clearUserCache($userID);
            Gdn::cache()->remove("Follow_{$userID}");
        }
    }

    /**
     * Unfollow all group's categories
     * @param $group
     * @param $userID
     */
    public function unfollowGroup($group, $userID) {
        $this->followGroup($group, $userID, false);
    }

    /**
     * Watch all group's categories
     * @param $group
     * @param $userID
     * @param int $watched 1 - watch, 0/null - unwatch
     */
    public function watchGroup($group, $userID, $watched = true) {
        if(is_numeric($group) && $group > 0) {
            $group = $this->getByGroupID($group);
        }

        $categories = Gdn::sql()->getWhere('Category', ['GroupID' => $group->GroupID, 'DisplayAs' => 'Discussions'])->resultArray();
        $categoryIDs = array_column($categories, 'CategoryID');
       // Don't use setCategoryMetaData due to cache
        $metaKeys = ['Preferences.Email.NewComment.',
            'Preferences.Email.NewDiscussion.',
            'Preferences.Popup.NewComment.',
            'Preferences.Popup.NewDiscussion.'];
        foreach($categoryIDs as $categoryID) {
            foreach ($metaKeys as $metaKey) {
                 if($watched) {
                    $this->SQL->replace(
                        'UserMeta',
                        [ 'Value' => (int)$watched],
                        [ 'UserID' => $userID, 'Name' => $metaKey . $categoryID,]
                    );
                } else {
                    Gdn::sql()->delete('UserMeta', [
                        'UserID' => $userID,
                        'Name' => $metaKey . $categoryID
                    ]);
                }
            }
        }
        CategoryModel::clearUserCache($userID);
        Gdn::cache()->remove("UserMeta_{$userID}");

    }

    /**
     * Unwatch all group's categories
     * @param $group
     * @param $userID
     */
    public function unwatchGroup($group, $userID) {
        $this->watchGroup($group,$userID,false);
    }


    /**
     * Check add group permission
     *
     */
    public function canAddGroup() {
        return Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_ADD_PERMISSION);
    }

    /**
     * Check manage group category permission
     *
     */
    public function canManageCategories($group) {
        if((int)$group->Archived === 1) {
            return false;
        }

        if($group->Type === GroupModel::TYPE_REGULAR) {
            return false;
        }

        return $this->isProjectCopilot() || $this->isProjectManager() || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_CATEGORY_MANAGE_PERMISSION);
    }

    private function isProjectCopilot() {
       return $this->checkTopcoderProjectRole(RoleModel::ROLE_TOPCODER_PROJECT_COPILOT);
    }

    private function isProjectManager(){
        return $this->checkTopcoderProjectRole(RoleModel::ROLE_TOPCODER_PROJECT_MANAGER);
    }

    private function isProjectObserver(){
       return $this->checkTopcoderProjectRole(RoleModel::ROLE_TOPCODER_PROJECT_OBSERVER);
    }

    private function checkTopcoderProjectRole($topcoderProjectRole){
        return $this->currentUserTopcoderProjectRoles? in_array($topcoderProjectRole, $this->currentUserTopcoderProjectRoles): false;
    }

    /**
     *
     * Check edit group permission
     *
     */
    public function canEdit($group) {
       if((int)$group->Archived === 1) {
           return false;
       }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
       if($groupRole == GroupModel::ROLE_LEADER ||
           Gdn::session()->UserID == $group->OwnerID ||
           $this->isProjectCopilot() || $this->isProjectManager() ||
           Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_EDIT_PERMISSION) ||
           Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
           return true;
       }

       return false;
    }

    /**
     * Check delete group permission
     *
     */
    public function canDelete($group){
        if((int)$group->Archived === 1) {
            return false;
        }
        return Gdn::session()->UserID == $group->OwnerID || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_DELETE_PERMISSION);
    }

    /**
     *  Check join group permission
     *
     */
    public function canJoin($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        return $group->Privacy == GroupModel::PRIVACY_PUBLIC;
    }

    /**
     *  Check remove member permission
     *
     */
    public function canRemoveMember($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check change group role permission
     *
     */
    public function canChangeGroupRole($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check leave group permission
     *
     */
    public function canLeave($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        if(isset($group->ChallengeID)) {
            return false;
        }
        if(Gdn::session()->UserID == $group->OwnerID) {
            return false;
        }

        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        return $groupRole != false;
    }

    /**
     *  Check manage members permission
     *
     */
    public function canManageMembers($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check invite member permission
     *
     */
    public function canInviteNewMember($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole === GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID === $group->OwnerID ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_EMAIL_INVITATIONS_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check add group discusion permission
     *
     */
    public function canAddDiscussion($group) {
        if((int)$group->Archived === 1) {
            return false;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole || Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check add  group announcement permission
     *
     */
    public function canAddAnnouncement($group) {
        if((int)$group->Archived === 1) {
            return false;
        }

        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
         || $this->isProjectCopilot() || $this->isProjectManager()) {
            return true;
        }
        return false;
    }

    /**
     *  Check view group discusion permission
     *
     */
    public function canViewDiscussion($discussion) {
        $groupID = $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID) {
            return true;
        }
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check edit group discusion permission
     *
     */
    public function canEditDiscussion($discussion) {
        $canEditDiscussion = DiscussionModel::canEdit($discussion) ;
        $groupID= $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID) {
            return $canEditDiscussion;
        }

        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);

        if(($groupRole && $discussion->InsertUserID == Gdn::session()->UserID)
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check dismiss group discusion permission
     *
     */
    public function canDismissDiscussion($discussion) {
        $canDismissDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Dismiss', true)
        && $discussion->Announce
        && !$discussion->Dismissed
        && Gdn::session()->isValid();

        $groupID= $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID ) {
            return $canDismissDiscussion;
        }

        if($canDismissDiscussion === false) {
            return $canDismissDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID ||
            $this->isProjectCopilot() || $this->isProjectManager() ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check announce group discusion permission
     *
     */
    public function canAnnounceDiscussion($discussion) {
        $canAnnounceDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Announce', true);
        $groupID = $this->findGroupIDFromDiscussion($discussion);

        if(!$groupID ) {
            return $canAnnounceDiscussion;
        }

        if($canAnnounceDiscussion === false) {
            return $canAnnounceDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole === GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID === $group->OwnerID ||
            $this->isProjectCopilot() || $this->isProjectManager() ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check announce group discusion permission
     *
     */
    public function canAddComment($categoryID, $groupID) {
        $canAddComment =  CategoryModel::checkPermission($categoryID, 'Vanilla.Comments.Add', true);
        if(!$groupID ) {
            return $canAddComment;
        }

        if($canAddComment === false) {
            return $canAddComment;
        }

        $group = $this->getByGroupID($groupID);
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check sink group discusion permission
     *
     */
    public function canSinkDiscussion($discussion) {
        $canSinkDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Sink', true);
        $groupID = $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID ) {
            return $canSinkDiscussion;
        }

        if($canSinkDiscussion === false) {
            return $canSinkDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole === GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID === $group->OwnerID ||
            $this->isProjectCopilot() || $this->isProjectManager() ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check close group discusion permission
     *
     */
    public function canCloseDiscussion($discussion) {
        $canCloseDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Close', true);
        $groupID = $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID ) {
            return $canCloseDiscussion;
        }

        if($canCloseDiscussion === false) {
            return $canCloseDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
        if($groupRole === GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID === $group->OwnerID ||
            $this->isProjectCopilot() || $this->isProjectManager() ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check move group discussion permission
     *
     */
    public function canMoveDiscussion($discussion) {
        if ($this->canEditDiscussion($discussion) && Gdn::session()->checkPermission('Garden.Moderation.Manage')) {
            return true;
        }

        $groupID = $this->findGroupIDFromDiscussion($discussion);
        if($groupID) {
            $group = $this->getByGroupID($groupID);
            $groupRole = self::getGroupRoleFor(Gdn::session()->UserID, $groupID);
            if ($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
                || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
                return true;
            }
        }
        return false;
    }

    public function canRefetchDiscussion($discussion) {
         return $this->canEditDiscussion($discussion);
    }

    public function canDeleteDiscussion($discussion) {
        $canDeleteDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Delete', true);
        $groupID = $this->findGroupIDFromDiscussion($discussion);
        if(!$groupID) {
             return $canDeleteDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        if($canDeleteDiscussion && Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     * Send invite email.
     *
     * @param int $userID
     * @param string $password
     */
    public function sendInviteEmail($GroupID, $userID) {
        $Group = $this->getByGroupID($GroupID);
        $session = Gdn::session();
        $sender = Gdn::userModel()->getID($session->UserID);
        $user = Gdn::userModel()->getID($userID);
        $email = new Gdn_Email();
        $email->subject($sender->Name.' invited you to '.$Group->Name);
        $email->to($user->Email);
        $greeting = 'Hello!';
        $message = $greeting.'<br/>'.
            'You can accept or decline this invitation.';

        $emailTemplate = $email->getEmailTemplate()
            ->setTitle($sender->Name.' invited you to '.$Group->Name)
            ->setMessage($message)
            ->setButton(externalUrl('/group/accept/'.$Group->GroupID.'?userID='.$userID), 'Accept' );

        $email->setEmailTemplate($emailTemplate);

        try {
            $email->send();
        } catch (Exception $e) {
            if (debug()) {
                throw $e;
            }
        }
    }

    public function notifyNewGroup($groupID, $groupName) {
        $activityModel = Gdn::getContainer()->get(ActivityModel::class);
        $data = [
            "ActivityType" => 'NewGroup',
            "ActivityUserID" => Gdn::session()->UserID,
            "HeadlineFormat" => '{ActivityUserID,user} created <a href="{Url,html}">{Data.Name,text}</a>',
            "RecordType" => "Group",
            "RecordID" => $groupID,
            "Route" => groupUrl($groupID, "", "/"),
            "Data" => [
                "Name" => $groupName,
            ]
        ];
        $activityModel->save($data);
    }

    public function notifyJoinGroup($groupID, $userID) {
        $group = $this->getID($groupID);

        $activityModel = Gdn::getContainer()->get(ActivityModel::class);
        $data = [
            "ActivityType" => 'JoinedGroup',
            "ActivityUserID" => $userID,
            "HeadlineFormat" => '{ActivityUserID,user} joined <a href="{Url,html}">{Data.Name,text}</a>',
            "RecordType" => "Group",
            "RecordID" => $group->GroupID,
            "Route" => groupUrl($group->GroupID, "", "/"),
            "Data" => [
                "Name" => $group->Name,
            ]
        ];
        $activityModel->save($data);
    }


    public function canArchiveGroup($group){
        return Gdn::session()->UserID == $group->OwnerID ||  Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_ARCHIVE_PERMISSION);
    }

    /**
     * Archive a group and its categories
     *
     * @param $group
     */
    public function archiveGroup($group){
        if(is_numeric($group) && $group > 0) {
            $group = $this->getByGroupID($group);
        }

       if($group->ChallengeID) {
            $categoryModel = new CategoryModel();
            $groupCategory = $categoryModel->getByCode($group->ChallengeID);
            if($groupCategory->DisplayAs !== 'Discussions') {
                $categories = CategoryModel::getSubtree($groupCategory->CategoryID, true);
                $categoryIDs = array_column($categories, 'CategoryID');
            } else {
                $categoryIDs = [$groupCategory->CategoryID];
            }

            foreach($categoryIDs as $categoryID) {
                $category = $categoryModel->getID($categoryID, DATASET_TYPE_ARRAY);
                $category['Archived'] = 1;
                $categoryModel->save($category);
            }
        }

        $group->Archived = 1;
        $this->save($group);
    }

    public function getGroupDiscussionCategories($group){
        if(is_numeric($group) && $group > 0) {
            $group = $this->getByGroupID($group);
        }
        $categoryModel = new  CategoryModel();
        return $categoryModel->getWhere(['GroupID' => $group->GroupID, 'DisplayAs' => 'Discussions'])->resultArray();
    }

    public function getRootGroupCategory($group){
        if(is_numeric($group) && $group > 0) {
            $group = $this->getByGroupID($group);
        }
        $categoryModel = new  CategoryModel();
        if($group->ChallengeID) {
            return $categoryModel->getByCode($group->ChallengeID);
        }

        return -1; //return Vanilla root
    }

    /**
     * Clear the cached UserGroup data for a specific user.
     *
     * @param int|null $userID The user to clear. Use `null` for the current user.
     */
    public static function clearUserGroupCache($userID = null) {
        if ($userID === null) {
            $userID = Gdn::session()->UserID;
        }

        $key = 'UserGroup_'.$userID;
        Gdn::cache()->remove($key);
    }
}