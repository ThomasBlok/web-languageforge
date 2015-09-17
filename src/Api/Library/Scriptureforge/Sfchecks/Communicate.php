<?php
namespace Api\Library\Scriptureforge\Sfchecks;

use Api\Library\Shared\Sms\SmsModel;
use Api\Library\Shared\Sms\SmsQueue;
use Api\Library\Shared\Website;
use Api\Model\MessageModel;
use Api\Model\ProjectModel;
use Api\Model\ProjectSettingsModel;
use Api\Model\UserModel;
use Api\Model\UnreadMessageModel;

class CommunicateDelivery implements IDelivery
{
    /**
     * (non-PHPdoc)
     * @see libraries\scriptureforge\sfchecks.IDelivery::sendEmail()
     */
    public function sendEmail($from, $to, $subject, $content)
    {
        if (!defined('TestMode')) {
            Email::send($from, $to, $subject, $content);
        }
    }

    /**
     * (non-PHPdoc)
     * @see libraries\scriptureforge\sfchecks.IDelivery::sendSms()
     */
    public function sendSms($smsModel)
    {
        SmsQueue::queue($smsModel);
    }

}

class CommunicateHelper
{

    /**
     *
     * @param string $fileName
     * @return \Twig_Template
     */
    public static function templateFromFile($fileName)
    {
        if (defined('TestMode')) {
            $options = array();
        } else {
            $options = array(
                    'cache' => APPPATH . 'cache',
            );
        }
        $loader = new \Twig_Loader_Filesystem(APPPATH . 'Site/views');
        $twig = new \Twig_Environment($loader, $options);
        $template = $twig->loadTemplate($fileName);

        return $template;
    }

    /**
     *
     * @param string $fileName
     * @return \Twig_Template
     */
    public static function templateFromString($templateCode)
    {
        if (defined('TestMode')) {
            $options = array();
        } else {
            $options = array(
                    'cache' => APPPATH . 'cache',
            );
        }
        $loader = new \Twig_Loader_String();
        $twig = new \Twig_Environment($loader, $options);
        $template = $twig->loadTemplate($templateCode);

        return $template;
    }

    /**
     *
     * @param SmsModel $smsModel
     * @param IDelivery $delivery
     */
    public static function deliverSMS($smsModel, IDelivery $delivery = null)
    {
        // Create our default delivery mechanism if one is not passed in.
        if ($delivery == null) {
            $delivery = new CommunicateDelivery();
        }

        // Deliver the sms message
        $delivery->sendSMS($smsModel);
    }

    /**
     *
     * @param mixed $from
     * @param mixed $to
     * @param string $subject
     * @param string $content
     * @param IDelivery $delivery
     */
    public static function deliverEmail($from, $to, $subject, $content, IDelivery $delivery = null)
    {
        // Create our default delivery mechanism if one is not passed in.
        if ($delivery == null) {
            $delivery = new CommunicateDelivery();
        }

        // Deliver the email message
        $delivery->sendEmail($from, $to, $subject, $content);
    }

}

class Communicate
{
    /**
     *
     * @param array $users array<UserModel>
     * @param ProjectSettingsModel $project
     * @param string $subject
     * @param string $smsTemplate
     * @param string $emailTemplate
     * @param IDelivery|null $delivery
     * @return string
     */
    public static function communicateToUsers($users, $project, $subject, $smsTemplate, $emailTemplate, IDelivery $delivery = null)
    {
        // store message in database
        $messageModel = new MessageModel($project);
        $messageModel->subject = $subject;
        $messageModel->content = $emailTemplate;
        $messageId = $messageModel->write();

        foreach ($users as $user) {
            self::communicateToUser($user, $project, $subject, $smsTemplate, $emailTemplate, $delivery);
            $unreadModel = new UnreadMessageModel($user->id->asString(), $project->id->asString());
            $unreadModel->markUnread($messageId);
            $unreadModel->write();
        }
        SmsQueue::processQueue($project->databaseName());

        return $messageId;
    }

    /**
     *
     * @param UserModel $user
     * @param ProjectSettingsModel $project
     * @param string $subject
     * @param string $smsTemplate
     * @param string $emailTemplate
     * @param IDelivery $delivery
     */
    public static function communicateToUser($user, $project, $subject, $smsTemplate, $emailTemplate, IDelivery $delivery = null)
    {
        $broadcastMessageContent = "";

        // Prepare the email message if required
        if ($user->communicate_via == UserModel::COMMUNICATE_VIA_EMAIL || $user->communicate_via == UserModel::COMMUNICATE_VIA_BOTH) {
            $from = array($project->emailSettings->fromAddress => $project->emailSettings->fromName);
            $to = array($user->email => $user->name);
            $vars = array(
                    'user' => $user,
                    'project' => $project
            );
            $t = CommunicateHelper::templateFromString($emailTemplate);
            $content = $t->render($vars);

            $broadcastMessageContent = $content;

            CommunicateHelper::deliverEmail($from, $to, $subject, $content, $delivery);
        }

        // Prepare the sms message if required
        if ($project->smsSettings->hasValidCredentials()) {
            if ($user->communicate_via == UserModel::COMMUNICATE_VIA_SMS || $user->communicate_via == UserModel::COMMUNICATE_VIA_BOTH) {
                $databaseName = $project->databaseName();
                $sms = new SmsModel($databaseName);
                $sms->providerInfo = $project->smsSettings->accountId . '|' . $project->smsSettings->authToken;
                $sms->to = $user->mobile_phone;
                $sms->from = $project->smsSettings->fromNumber;
                $vars = array(
                    'user' => $user,
                    'project' => $project
                );
                $t = CommunicateHelper::templateFromString($smsTemplate);
                $sms->message = $t->render($vars);

                CommunicateHelper::deliverSMS($sms, $delivery);
            }
        }
    }

    /**
     * Send an email to validate a user when they sign up.
     * @param UserModelBase $userModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendSignup($userModel, $website, IDelivery $delivery = null)
    {
        $userModel->setValidation(7);
        $userModel->write();

        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = $website->name . ' account signup validation';

        $vars = array(
                'user' => $userModel,
                'link' => $website->baseUrl() . '/validate/' . $userModel->validationKey,
                'website' => $website
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/SignupValidate.html";
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/SignupValidate.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);

        CommunicateHelper::deliverEmail(
            $from,
            array($userModel->emailPending => $userModel->name),
            $subject,
            $html,
            $delivery
        );
    }

    /**
     *
     * @param UserModelBase $inviterUserModel
     * @param UserModelBase $toUserModel
     * @param ProjectModel $projectModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendInvite($inviterUserModel, $toUserModel, $projectModel, $website, IDelivery $delivery = null)
    {
        $toUserModel->setValidation(7);
        $toUserModel->write();

        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = $website->name . ' account signup validation';

        $vars = array(
            'user' => $inviterUserModel,
            'project' => $projectModel,
            'link' => $website->baseUrl() . '/registration#/?v=' . $toUserModel->validationKey,
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/InvitationValidate.html";
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/InvitationValidate.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);

        CommunicateHelper::deliverEmail(
            $from,
            array($toUserModel->emailPending => $toUserModel->name),
            $subject,
            $html,
            $delivery
        );
    }

    /**
     *
     * @param UserModel $toUserModel
     * @param string $newUserName
     * @param string $newUserPassword
     * @param ProjectModel $project
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendNewUserInProject($toUserModel, $newUserName, $newUserPassword, $project, $website, IDelivery $delivery = null)
    {
        $vars = array(
                'user' => $toUserModel,
                'newUserName' => $newUserName,
                'newUserPassword' => $newUserPassword,
                'website' => $website,
                'project' => $project
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/NewUserInProject.html";
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/NewUserInProject.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);

        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = $website->name . ' new user login for project ' . $project->projectName;

        CommunicateHelper::deliverEmail(
            $from,
            array($toUserModel->email => $toUserModel->name),
            $subject,
            $html,
            $delivery
        );
    }

    /**
     * Notify existing user they've been added to a project
     * @param UserModel $inviterUserModel
     * @param UserModel $toUserModel
     * @param ProjectModel $projectModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendAddedToProject($inviterUserModel, $toUserModel, $projectModel, $website, IDelivery $delivery = null)
    {
        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = 'You\'ve been added to the project ' . $projectModel->projectName . ' on ' . $website->name;

        $vars = array(
            'toUser' => $toUserModel,
            'inviterUser' => $inviterUserModel,
            'project' => $projectModel
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/AddedToProject.html";
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/AddedToProject.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);

        CommunicateHelper::deliverEmail(
            $from,
            array($toUserModel->email => $toUserModel->name),
            $subject,
            $html,
            $delivery
        );
    }
    
    /**
     *
     * @param UserModelBase $inviterUserModel
     * @param UserModelBase $toUserModel
     * @param ProjectModel $projectModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendJoinRequestConfirmation($user, $projectModel, $website, IDelivery $delivery = null)
    {
        $user->setValidation(7);
        $user->write();
    
        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = 'You\'ve submitted a join request to the project ' . $projectModel->projectName . ' on ' . $website->name;        
    
        $vars = array(
            'user' => $user,
            'project' => $projectModel,
        );
    
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/JoinRequestConfirmation.html";
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/JoinRequestConfirmation.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);
    
        CommunicateHelper::deliverEmail(
            $from,
            array($user->email => $user->name),
            $subject,
            $html,
            $delivery
        );
    }
    /**
     *
     * @param UserModelBase $inviterUserModel
     * @param UserModelBase $toUserModel
     * @param ProjectModel $projectModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendJoinRequest($user, $admin, $projectModel, $website, IDelivery $delivery = null)
    {
        $u = new UserModel();
        $user->setValidation(7);
        $user->write();
    
        $senderEmail = $user->email;
        $from = array($senderEmail => $user->name);
        $subject = $user->name . ' join request';
    
        $vars = array(
            'user' => $user,
            'admin' => $admin,
            'project' => $projectModel,
            'link' => $website->baseUrl() . "/app/usermanagement/" . $projectModel->id->asString() . "#/joinRequests",
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/JoinRequest.html";
    
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/JoinRequest.html";
        }
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);
    
        CommunicateHelper::deliverEmail(
            $from,
            array($admin->email => $admin->name),
            $subject,
            $html,
            $delivery
        );
    }
    
    /**
     *
     * @param UserModelBase $inviterUserModel
     * @param UserModelBase $toUserModel
     * @param ProjectModel $projectModel
     * @param Website $website
     * @param IDelivery $delivery
     */
    public static function sendJoinRequestAccepted($user, $projectModel, $website, IDelivery $delivery = null)
    {
        $senderEmail = 'no-reply@' . $website->domain;
        $from = array($senderEmail => $website->name);
        $subject = 'You\'ve submitted a join request to the project ' . $projectModel->projectName . ' on ' . $website->name;        
    
        $vars = array(
            'user' => $user,
            'project' => $projectModel,
            'link' => $website->baseUrl() . "/app/semdomtrans/" . $projectModel->id->asString() . "#/edit",
        );
        $templateFile = $website->base . "/theme/" . $website->theme . "/email/en/JoinRequestAccepted.html";
    
        if (! file_exists($templateFile)) {
            $templateFile = $website->base . "/theme/default/email/en/JoinRequestAccepted.html";
        }
        
        $t = CommunicateHelper::templateFromFile($templateFile);
        $html = $t->render($vars);
    
        CommunicateHelper::deliverEmail(
            $from,
            array($user->email => $user->name),
            $subject,
            $html,
            $delivery
        );
    }
}