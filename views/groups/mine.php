<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();

include_once $this->fetchViewLocation('helper_functions');

echo '<h1 class="H HomepageTitle">Challenge Forums</h1>';
if ($this->data('ChallengeGroups')) {

    ?>
    <ul class="DataList GroupList">
        <?php
        echo writeGroups($this->data('ChallengeGroups'), $this);
        ?>
    </ul>
    <?php
    if ($this->data('CountOfChallengeGroups') > 0) {
        ?>
    <div class="MoreWrap"> <?php echo anchor('All Challenge Forums('.$this->data('CountOfChallengeGroups').')', '/groups/mine?filter=challenge', 'MoreWrap');?></div>
        <?php
    } else {
        ?>
        <div class="Empty"><?php echo t('No Challenge Forums were found.'); ?></div>
        <?php
    }
    ?>
<?php
}

echo '<h1 class="H HomepageTitle">Group Discussions</h1>';
if ($this->data('RegularGroups')) {

    echo '<div class="media-list-container Group-Box my-groups">';
    ?>
    <ul class="DataList GroupList">
        <?php
        echo writeGroups($this->data('RegularGroups'), $this);
        ?>
    </ul>
    <?php
        if ($this->data('CountOfRegularGroups') > 0) {
    ?>
        <div class="MoreWrap"> <?php echo anchor('All Group Forums('.$this->data('CountOfRegularGroups').')', '/groups/mine/?filter=regular', 'MoreWrap');?></div>
    <?php
        } else {
            ?>
            <div class="Empty"><?php echo t('No Group Forums were found.'); ?></div>
            <?php
        }
    ?>
<?php
}
echo '</div>';