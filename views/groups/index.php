<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();
$groupModel = new GroupModel();
$canAddGroup = $groupModel->canAddGroup();

include_once $this->fetchViewLocation('helper_functions');

if($canAddGroup === true) {
    echo '<div class="groupToolbar"><a href="/group/add" class="Button Primary groupToolbar-newGroup">New Challenge</a></div>';
}


echo '<div class="media-list-container Group-Box my-groups">';
        echo '<div class="PageControls">';
        echo '<h2 class="H HomepageTitle">My Challenges</h2>';
        echo '</div>';

    if ($this->GroupData->numRows() > 0 ) {
        ?>
        <h2 class="sr-only"><?php echo t('Challenge List'); ?></h2>
        <ul class="media-list DataList">
            <?php echo writeGroups($this->GroupData, $this); ?>
        </ul>
        <div class="MoreWrap"> <?php echo anchor('All Challenges', '/groups/mine', 'MoreWrap');?></div>
        <?php
    } else {
        ?>
        <div class="Empty"><?php echo t('No challenges were found.'); ?></div>
    <?php
    }
echo '</div>';

echo '<div class="media-list-container Group-Box my-groups">';
echo '<div class="PageControls">';
echo '<h2 class="H HomepageTitle">Available Challenges</h2>';
echo '</div>';

if ($this->AvailableGroupData->numRows() > 0 ) {
    ?>
    <h2 class="sr-only"><?php echo t('Challenge List'); ?></h2>
    <ul class="media-list DataList">
        <?php echo writeGroups($this->AvailableGroupData, $this); ?>
    </ul>
    <div class="MoreWrap"> <?php echo anchor('All Available Challenges', '/groups/all', 'MoreWrap');?></div>
    <?php
} else {
    ?>
    <div class="Empty"><?php echo t('No challenges were found.'); ?></div>
    <?php
}
echo '</div>';