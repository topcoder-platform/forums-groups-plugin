<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Invite User'); ?></h1>
<?php
$Group = $this->data('Group');
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <?php
    echo '<div class="P">Are you sure you want to invite User to \''. $Group->Name.'\'?</div>';
    echo '<div class="P">';
    echo $this->Form->label('Username', 'Username');
    echo wrap($this->Form->textBox('Username', ['maxlength' => 100, 'class' => 'InputBox BigInput']), 'div', ['class' => 'TextBoxWrapper']);
    echo '</div>';
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Invite', ['class' => 'Button Primary GroupButton']);
    echo '</div>';

    ?>
</div>