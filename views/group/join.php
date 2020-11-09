<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Join'); ?></h1>
<?php
$Group = $this->data('Group');
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <?php
    echo '<div class="P">Are you sure you want to join \''. $Group->Name.'\'?</div>';
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Join', ['class' => 'Button Primary GroupButton']);
    echo '</div>';

    ?>
</div>