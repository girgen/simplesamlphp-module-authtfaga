<?php

/**
 * @author Tamas Frank, NIIFI
 *
 */
// Get session object
$session = SimpleSAML\Session::getSession();

// Get the authetication state
$authStateId = $_REQUEST['AuthState'];
/** @noinspection PhpUnhandledExceptionInspection */
$state = SimpleSAML\Auth\State::loadState($authStateId, 'authtfaga.stage');

if(array_key_exists("SimpleSAML_Auth_Source.id", $state)) $authId = $state['SimpleSAML_Auth_Source.id'];
else $authId = $state['\SimpleSAML\Auth\Source.id'];

/** @noinspection PhpUnhandledExceptionInspection */
$as = SimpleSAML\Configuration::getConfig('authsources.php')->getValue($authId);

// Use 2 factor authentication classvar_dump($authId);
/** @noinspection PhpUnhandledExceptionInspection */
/** @var sspmod_authtfaga_Auth_Source_authtfaga $gaLogin */
$gaLogin = SimpleSAML\Auth\Source::getById($authId, 'sspmod_authtfaga_Auth_Source_authtfaga');
if ($gaLogin === null) {
	/** @noinspection PhpUnhandledExceptionInspection */
	throw new Exception('Invalid authentication source: ' . $authId);
}

// Init template
$template = 'authtfaga:login.php';
/** @noinspection PhpUnhandledExceptionInspection */
$globalConfig = SimpleSAML\Configuration::getInstance();
/** @noinspection PhpParamsInspection */
$t = new SimpleSAML\XHTML\Template($globalConfig, $template);

$errorCode = null;

//If user doesn't have session, force to use the main authentication method
if (!$session->isValid($as['mainAuthSource'])) {
	/** @noinspection PhpUnhandledExceptionInspection */
	$mainLogin = SimpleSAML\Auth\Source::getById($as['mainAuthSource']);
	$mainLogin->initLogin(SimpleSAML\Utils\HTTP::getSelfURL());
}

$attributes = $session->getAuthData($as['mainAuthSource'], 'Attributes');
$state['Attributes'] = $attributes;

$uid = $attributes[$as['uidField']][0];
$state['UserID'] = $uid;
$isEnabled = $gaLogin->isEnabled2fa($uid);

if (is_null($isEnabled) || isset($_GET['postSetEnable2fa'])) {
    //If the user has not set his preference of 2 factor authentication, redirect to settings page
    if (isset($_POST['setEnable2f'])) {
        if ($_POST['setEnable2f'] == 1) {
            $gaKey = $gaLogin->createSecret();
            $gaLogin->registerGAkey($uid, $gaKey);

            $gaLogin->enable2fa($uid);
            $t->data['todo'] = 'generateGA';
            $t->data['autofocus'] = 'otp';
            $totpIssuer = empty($as['totpIssuer']) ? 'dev_aai_teszt_IdP' : $as['totpIssuer'];
            $t->data['qrcode'] = $gaLogin->getQRCodeGoogleUrl($totpIssuer . ':' . $uid, $totpIssuer, $gaKey);
        } elseif ($_POST['setEnable2f'] == 0) {
            $gaLogin->disable2fa($uid);
            SimpleSAML\Auth\Source::completeAuth($state);
        }
    } else {
        $t->data['todo'] = 'choose2enable';
    }
} elseif ($isEnabled == 1) {
    //Show the second factor form
    if (isset($_POST['otp'])) {
        $secret = $gaLogin->getGAkeyFromUID($uid);
        $loggedIn = $gaLogin->verifyCode($secret, $_POST['otp']);

        if ($loggedIn) {
            $state['saml:AuthnContextClassRef'] = $gaLogin->tfa_authencontextclassref;

            if (isset($state['Attributes']['userCertificate;binary'])) {
                unset($state['Attributes']['userCertificate;binary']);
            }

            SimpleSAML\Auth\Source::completeAuth($state);
        } else {
            $errorCode = 'WRONGOTP';
            $t->data['todo'] = 'loginOTP';
        }
    } else {
        $t->data['autofocus'] = 'otp';
        $t->data['todo'] = 'loginOTP';
    }
} else {

    if (isset($state['Attributes']['userCertificate;binary'])) {
        unset($state['Attributes']['userCertificate;binary']);
    }

    // User has set up not to use 2 factor, so he is logged in
    SimpleSAML\Auth\Source::completeAuth($state);
}

$t->data['stateparams'] = array('AuthState' => $authStateId);
$t->data['errorcode'] = $errorCode;
$t->show();
exit();
