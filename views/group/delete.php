<?php if (!defined('APPLICATION')) exit(); ?>
<?php
$Group = $this->data('Group');
?>
<h1><?php echo 'Delete' ?></h1>
<?php
echo $this->Form->open();
echo $this->Form->errors();
?>

<div class="Wrap">

    <?php
        echo '<div class="P Message">Are you sure you want to delete this item?</div>';
        echo '<div class="Buttons Buttons-Confirm">';
        echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
        echo $this->Form->button( 'Delete', ['class' => 'Button Primary Delete']);
        echo '</div>';
    ?>
</div>