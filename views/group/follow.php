<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Follow all categories'); ?></h1>
<?php
$Group = $this->data('Group');
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <?php
    echo '<div class="P">Are you sure you want to follow all categories?</div>';
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Follow', ['class' => 'Button Primary GroupButton']);
    echo '</div>';

    ?>
</div>