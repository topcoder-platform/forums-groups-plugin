<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();

include_once $this->fetchViewLocation('helper_functions');


echo '<div class="media-list-container Group-Box my-groups">';
echo '<div class="">';
echo '<h2 class="H HomepageTitle">My Challenges</h2>';
echo '</div>';

if ($this->data('ChallengeGroups')) {
    ?>
    <ul class="media-list DataList">
        <?php
        echo writeGroups($this->data('ChallengeGroups'), $this);
        ?>
    </ul>
    <?php
    if ($this->data('CountOfChallengeGroups') > 0) {
        ?>
    <div class="MoreWrap"> <?php echo anchor('All My Challenges('.$this->data('CountOfChallengeGroups').')', '/groups/mine?filter=challenge', 'MoreWrap');?></div>
        <?php
    } else {
        ?>
        <div class="Empty"><?php echo t('No challenges were found.'); ?></div>
        <?php
    }
    ?>
<?php
}
echo '</div>';

echo '<div class="media-list-container Group-Box my-groups">';
echo '<div class="">';
echo '<h2 class="H HomepageTitle">My Groups</h2>';
echo '</div>';

if ($this->data('RegularGroups')) {
    ?>
    <ul class="media-list DataList">
        <?php
        echo writeGroups($this->data('RegularGroups'), $this);
        ?>
    </ul>
    <?php
        if ($this->data('CountOfRegularGroups') > 0) {
    ?>
        <div class="MoreWrap"> <?php echo anchor('All My Groups('.$this->data('CountOfRegularGroups').')', '/groups/mine/?filter=regular', 'MoreWrap');?></div>
    <?php
        } else {
            ?>
            <div class="Empty"><?php echo t('No groups were found.'); ?></div>
            <?php
        }
    ?>
<?php
}
echo '</div>';