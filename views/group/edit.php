<?php if (!defined('APPLICATION')) exit();

$CancelUrl = $this->data('_CancelUrl');
if (!$CancelUrl) {
    $CancelUrl = '/groups';
}
?>
<div id="GroupForm" class="FormTitleWrapper GroupForm">
    <?php
    if ($this->deliveryType() == DELIVERY_TYPE_ALL) {
        echo wrap($this->data('Title'), 'h1', ['class' => 'H']);
    }
    echo '<div class="FormWrapper">';
    echo $this->Form->open(['enctype' => 'multipart/form-data']);
    echo $this->Form->errors();


    echo '<div class="P">';
    echo $this->Form->label('Name', 'Name');
    echo wrap($this->Form->textBox('Name', ['maxlength' => 100, 'class' => 'InputBox BigInput', 'spellcheck' => 'true']), 'div', ['class' => 'TextBoxWrapper']);
    echo '</div>';

    echo '<div class="P">';
    echo $this->Form->label('Description', 'Description');
    echo '<div class="TextBoxWrapper">'.$this->Form->textBox('Description', ['MultiLine' => true]).'</div>';
    echo '</div>';

    echo '<div class="P">';
    echo $this->Form->label('Type', 'Type');
    echo $this->Form->dropDown('Type', $this->data('Types'));
    echo '</div>';

    echo '<div class="P">';
    echo $this->Form->label('Archived', 'Archived');
    echo $this->Form->checkbox('Archived','Is Archived?', ['value' => '1']);
    echo '</div>';

    echo '<div class="P">';
    echo $this->Form->label('Icon', 'Icon');
    echo $this->Form->imageUploadPreview('Icon');

    echo '</div>';

    echo '<div class="P">';
    echo $this->Form->label('Banner', 'Banner');
    echo $this->Form->imageUploadPreview('Banner');
    echo '</div>';

    echo '<div class="P">';
    echo '<div><b>Privacy</b></div>';
    echo $this->Form->radioList('Privacy',$this->data('PrivacyTypes'), ['Default' =>  GroupModel::PRIVACY_PUBLIC]);
    echo '</div>';

    echo '<div class="Buttons">';
    echo $this->Form->button( 'Save', ['class' => 'Button Primary']);
    echo anchor(t('Cancel'), $CancelUrl, 'Button');
   // echo ' '.anchor(t('Edit'), '#', 'Button WriteButton Hidden')."\n";
    echo '</div>';

    echo $this->Form->close();
    echo '</div>';
    ?>
</div>
