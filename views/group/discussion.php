<?php if (!defined('APPLICATION')) exit();

$Group = $this->data('Group');
$announce = $this->data('Announce');
$CancelUrl = '/group/' . $Group->GroupID;

?>
<div id="DiscussionForm" class="FormTitleWrapper DiscussionForm">
    <?php
    if ($this->deliveryType() == DELIVERY_TYPE_ALL) {
        echo wrap($this->data('Title'), 'h1', ['class' => 'H']);
    }
    echo '<div class="FormWrapper">';
    echo $this->Form->open();
    echo $this->Form->errors();

    $this->fireEvent('BeforeFormInputs');

    if ($this->ShowCategorySelector === true) {
        $options = ['Value' => val('CategoryID', $this->Category), 'IncludeNull' => true];
        if ($this->Context) {
            $options['Context'] = $this->Context;
        }
        $discussionType = property_exists($this, 'Type') ? $this->Type : $this->data('Type');
        if ($discussionType) {
            $options['DiscussionType'] = $discussionType;
        }
        echo '<div class="P">';
        echo '<div class="Category">';
        echo $this->Form->label('Category', 'CategoryID'), ' ';
        echo $this->Form->categoryDropDown('CategoryID', $options);
        echo '</div>';
        echo '</div>';
    }

    echo '<div class="P">';
    echo $this->Form->label('Title', 'Name');
    echo wrap($this->Form->textBox('Name', ['maxlength' => 100, 'class' => 'InputBox BigInput', 'spellcheck' => 'true']), 'div', ['class' => 'TextBoxWrapper']);
    echo '</div>';

    $this->fireEvent('BeforeBodyInput');

    echo '<div class="P">';
    echo $this->Form->bodyBox('Body', ['Table' => 'Discussion', 'FileUpload' => true, 'placeholder' => t('Type your message'), 'title' => t('Type your message')]);
    echo '</div>';

    $Options = '';

    $this->EventArguments['Options'] = &$Options;
    $this->fireEvent('DiscussionFormOptions');

    if ($Options != '') {
        echo '<div class="P">';
        echo '<ul class="List Inline PostOptions">'.$Options.'</ul>';
        echo '</div>';
    }

    $this->fireEvent('AfterDiscussionFormOptions');

    echo '<div class="Buttons">';
    $this->fireEvent('BeforeFormButtons');
    echo anchor(t('Cancel'), $CancelUrl, 'Button Cancel');
    // if (!property_exists($this, 'Discussion') || !is_object($this->Discussion) || (property_exists($this, 'Draft') && is_object($this->Draft))) {
    //    echo $this->Form->button('Save Draft', ['class' => 'Button DraftButton']);
    // }
    echo $this->Form->button((property_exists($this, 'Discussion')) ? 'Save' : ($announce? 'Post Announcement' : 'Post Discussion'), ['class' => 'Button Primary DiscussionButton']);
    echo $this->Form->button('Preview', ['class' => 'Button PreviewButton']);
    echo ' '.anchor(t('Edit'), '#', 'Button WriteButton Hidden')."\n";
    $this->fireEvent('AfterFormButtons');

    echo '</div>';

    echo $this->Form->close();
    echo '</div>';
    ?>
</div>
