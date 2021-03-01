<?php
/**
 * Group Invitation model.
 */

/**
 * Handles group invitation data.
 */
class GroupInvitationModel extends Gdn_Model {

    /**
     * Class constructor. Defines the related database table name.
     */
    public function __construct() {
        parent::__construct('GroupInvitation');
    }

    /**
     *
     *
     * @param $groupInvitationID
     * @return array|bool|stdClass
     */
    public function getByGroupInvitationID($groupInvitationID) {
        $dataSet = $this->SQL->from('GroupInvitation gi')
            ->join('Group g', 'gi.GroupID = g.GroupID')
            ->join('User ibyu', 'gi.InvitedByUserID = ibyu.UserID')
            ->join('User iu', 'gi.InviteeUserID = iu.UserID', 'left')
            ->select('gi.*')
            ->select('g.Name', '','GroupName')
            ->select('iu.UserID', '', 'InviteeUserID')
            ->select('iu.Email', '', 'InviteeEmail')
            ->select('iu.Name', '', 'InviteeName')
            ->select('ibyu.UserID', '', 'InvitedByUserID')
            ->select('ibyu.Email', '', 'InvitedByEmail')
            ->select('ibyu.Name', '', 'InvitedByName')
            ->where('gi.GroupInvitationID', $groupInvitationID)
            ->get();
        return $dataSet->firstRow();
    }


    private  function generateToken(){
        $strongResult = true;
        // Returns the generated string of bytes on success, or false on failure
        $randomString = openssl_random_pseudo_bytes(16, $strongResult);
        if($randomString === false) {
            throw new Exception('Couldn\'t generate a random string');
        }

        return bin2hex($randomString);
    }

    /**
     *
     *
     * @param array $formPostValues
     * @param array|bool $settings
     * @throws Exception
     * @return bool|array
     */
    public function save($formPostValues, $settings = false) {
        $sendEmail = val('SendEmail', $settings, true);
        $returnRow = val('ReturnRow', $settings, false);
        $insert = $formPostValues['GroupInvitationID'] > 0? false: true;

        // Define the primary key in this model's table.
        $this->defineSchema();

        if($insert) {
            $formPostValues['InvitedByUserID'] = Gdn::session()->UserID;
            $formPostValues['Token'] = self::generateToken();

            $expires = strtotime(c('Plugins.Groups.InviteExpiration', '+1 day'));
            $formPostValues['DateExpires'] = Gdn_Format::toDateTime($expires);
            $formPostValues['Status'] = 'pending';
            // Make sure required db fields are present.
            $this->addInsertFields($formPostValues);
        } else {
            $this->addUpdateFields($formPostValues);
        }

        if(!$insert) {
           $invitationID = $this->update(['Status' => $formPostValues['Status'], 'DateAccepted' => $formPostValues['DateAccepted']], ['GroupInvitationID' => $formPostValues['GroupInvitationID']] );
           return $invitationID;
        }

        // Validate the form posted values
        if ($this->validate($formPostValues, $insert) === true) {
            $groupModel = new GroupModel();
             // Make sure this user has permissions
             $hasPermission = $groupModel->canInviteNewMember($formPostValues['GroupID']);
            if (!$hasPermission) {
                $this->Validation->addValidationResult('GroupID', 'You do not have permissions to invite new members.');
                return false;
            }

            $now = Gdn_Format::toDateTime();
            $testData = $this->getWhere(['InviteeUserID' => $formPostValues['InviteeUserID'], 'GroupID' => $formPostValues['GroupID'],
                'Status' => 'pending', 'DateExpires >=' => $now])->result(DATASET_TYPE_ARRAY);
            if (count($testData)> 0) {
                // Check status
                $this->Validation->addValidationResult('InviteeUserID', 'An invitation has already been sent to this user.');
                return false;
            }

            // Call the base model for saving
            $invitationID = parent::save($formPostValues);

            // And send the invitation email
            if ($sendEmail) {
                try {
                    $this->send($invitationID);
                } catch (Exception $ex) {
                    $this->Validation->addValidationResult('Email', sprintf(t('Although the group invitation was created successfully, the email failed to send. The server reported the following error: %s'), strip_tags($ex->getMessage())));
                    return false;
                }
            }

            if ($returnRow) {
                return (array)$this->getByGroupInvitationID($invitationID);
            } else {
                return true;
            }
        }
        return false;
    }



    /**
     * Send Group Invitation by Email
     *
     * @param $groupInvitationID
     * @throws Exception
     */
    public function send($groupInvitationID) {
        $invitation = $this->getByGroupInvitationID($groupInvitationID);
        $email = new Gdn_Email();
        $email->subject($invitation->InvitedByName.' invited you to '.$invitation->GroupName);
        $email->to($invitation->InviteeEmail);
        $greeting = 'Hello!';
        $message = $greeting.'<br/>'.
            'You can accept or decline this invitation.';

        $emailTemplate = $email->getEmailTemplate()
            ->setTitle($invitation->InvitedByName.' invited you to '.$invitation->GroupName)
            ->setMessage($message)
            ->setButton(externalUrl('/group/accept/'.$invitation->Token), 'Accept' );
        $email->setEmailTemplate($emailTemplate);

        try {
            $email->send();
        } catch (Exception $e) {
            if (debug()) {
                throw $e;
            }
        }
    }

    /**
     * Validate token
     * @param $token
     * @param bool $returnData
     * @return array|bool|stdClass
     */
    public function validateToken($token, $returnData = true){
        if(empty($token)){
            $this->Validation->addValidationResult('Token', 'Invalid token');
            return false;
        }
        $userID = Gdn::session()->UserID;
        // One row only, token is unique
        $testData = $this->getWhere(['Token' => $token])->firstRow(DATASET_TYPE_ARRAY);
        if ($testData) {
             if($testData['InviteeUserID'] != $userID) {
                 $this->Validation->addValidationResult('Token', 'Invalid token');
                 return false;
             }
            $now = Gdn_Format::toDateTime();
             if($now > $testData['DateExpires']) {
                 $this->Validation->addValidationResult('Token', 'Your token has expired.');
                 return false;
             }

             if($returnData) {
                 return $testData;
             } else {
                 return true;
             }

        } else {
            $this->Validation->addValidationResult('Token', 'Invalid token.');
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($where = [], $options = []) {
        throw new Exception("Not supported");
    }

    /**
     * {@inheritdoc}
     */
    public function deleteID($id, $options = []) {
        throw new Exception("Not supported");
    }
}