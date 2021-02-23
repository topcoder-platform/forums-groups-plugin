<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();
$groupModel = new GroupModel();
//$currentTopcoderProjectRoles = Gdn::controller()->data('ChallengeCurrentUserProjectRoles');
//$groupModel->setCurrentUserTopcoderProjectRoles($currentTopcoderProjectRoles);

$canAddGroup = $groupModel->canAddGroup();

include_once $this->fetchViewLocation('helper_functions');

if($canAddGroup === true && $this->data('ShowAddButton') === true) {
    echo '<div class="groupToolbar"><a href="'.$this->data('AddButtonLink').'" class="Button Primary groupToolbar-newGroup">'. $this->data('AddButtonTitle').'</a></div>';
}

echo '<h1 class="H HomepageTitle">'.$this->data('Title').'</h1>';
echo '<div class="DataListWrap">';
echo writeGroupSection($this->data('Groups'),  $this->data('GroupsPager') , $this->data('Title'), $this->data('NoGroups'),$this->data('MyGroupButtonTitle'), $this->data('MyGroupButtonLink'),$this);
echo '</div>';
//echo writeGroupSection($this->data('AvailableGroups'), null, $this->data('AvailableGroupTitle'), $this->data('NoGroups'),$this->data('AllGroupButtonTitle'), $this->data('AllGroupButtonLink'),$this);
