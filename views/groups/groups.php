<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();

if (!function_exists('writeGroup')) {
    include($this->fetchViewLocation('helper_functions', 'groups', 'vanilla'));
}

echo writeGroups($this->data('Groups'), $this);
