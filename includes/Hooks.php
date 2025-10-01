<?php
/**
 * SpecialPageCaptcha extension - forces anonymous users to go through a CAPTCHA to access Special: pages.
 * Blame greedy AI companies for the existence of this atrocity of an extension.
 *
 * @file
 * @date 29 June 2025
 * @author Jack Phoenix
 */
namespace MediaWiki\Extension\SpecialPageCaptcha;

use MediaWiki\Extension\ConfirmEdit\Hooks as ConfirmEditHooks;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;

class Hooks implements SpecialPageBeforeExecuteHook {
	/**
	 * Main extension logic/hook handler.
	 *
	 * @param SpecialPage $specialPage
	 * @param string $subPage
	 * @return void|bool Boolean false if our code here was triggered, i.e. user is an anon subject to restrictions,
	 *    bool true if the cookie is set and the user has passed a CAPTCHA within the past half an hour; void if they
	 *    passed the CAPTCHA just now and the cookie was set
	 */
	public function onSpecialPageBeforeExecute( $specialPage, $subPage ) {
		// If the ConfirmEdit (CAPTCHA) extension isn't installed, bail out.
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			return;
		}

		$config = $specialPage->getConfig();
		$out = $specialPage->getOutput();
		$request = $specialPage->getRequest();
		$user = $specialPage->getUser();

		$services = MediaWikiServices::getInstance();

		// "Fun" fact: apparently you can't inject SpecialPageFactory via DI here (???) *and*
		// $specialPage->getSpecialPageFactory() is protected :^)
		$canonicalName = $services->getSpecialPageFactory()->resolveAlias( $specialPage->getName() )[0];
		$isWhitelisted = in_array( $canonicalName, $config->get( 'SpecialPageCaptchaWhitelist' ) );

		if ( !$user->isRegistered() && !$isWhitelisted ) {
			if ( $request->getCookie( 'SpecialPageCaptchaPass' ) === '1' ) {
				// You shall pass.
				return true;
			}

			$captcha = ConfirmEditHooks::getInstance();
			$pass = $captcha->passCaptchaFromRequest( $request, $user );
			$canSkip = $captcha->canSkipCaptcha( $user, $services->getMainConfig() );
			$wasPosted = $request->wasPosted();

			if (
				/*(
					$captcha->triggersCaptcha( 'edit' ) ||
					$captcha->triggersCaptcha( 'create' ) ||
					$captcha->triggersCaptcha( 'addurl' )
				) &&*/
				( !$canSkip && !$pass ) || !$wasPosted
			) {
				// Too Many Requests
				$out->setStatusCode( 429 );
				$out->setPageTitle( $specialPage->msg( 'error' )->escaped() );
				if ( $wasPosted && !$pass ) {
					// Show an error box when the answer to the CAPTCHA was incorrect
					$out->addHTML( Html::errorBox( $specialPage->msg( 'captcha-edit-fail' )->parse() ) );
				}
				$out->addHTML( '<form action="" method="post">' );
				$out->addHTML( $this->getCAPTCHAForm( $user, $out ) );
				$out->addHTML( Html::submitButton( $specialPage->msg( 'htmlform-submit' )->text() ) );
				$out->addHTML( '</form>' );
				return false;
			} elseif ( ( $wasPosted && $pass ) || $canSkip ) {
				// Set a cookie for the specified time (half an hour by default; can be configured by sysadmins
				// using theconfig variable to be shorter or longer)
				$request->response()->setCookie(
					'SpecialPageCaptchaPass',
					'1',
					time() + $config->get( 'SpecialPageCaptchaCookieTTL' )
				);
			}
		}
	}

	/**
	 * If the user is subject to CAPTCHAs, get a CAPTCHA form for them.
	 *
	 * @param User $user
	 * @param OutputPage $out
	 * @return string HTML
	 */
	private function getCAPTCHAForm( $user, $out ) {
		$captchaForm = '';
		$captcha = ConfirmEditHooks::getInstance();

		if (
			// @todo I hate this conditional, but ConfirmEdit's shouldCheck() -- which,
			// I guess, we should be using here, has an atrocious interface.
			// It assumes that we have a WikiPage, a Title and even a Content object.
			// It didn't work out for the CreateAPage extension (from which this code was
			// copied and ever so slightly tweaked), it definitely doesn't work for us here.
			// Thus we partially reimplement shouldCheck() here, sadly...
			/*
			(
				$captcha->triggersCaptcha( 'edit' ) ||
				$captcha->triggersCaptcha( 'create' ) ||
				$captcha->triggersCaptcha( 'addurl' )
			) &&
			*/
			!$captcha->canSkipCaptcha( $user, MediaWikiServices::getInstance()->getMainConfig() )
		) {
			$formInformation = $captcha->getFormInformation();
			$formMetainfo = $formInformation;
			unset( $formMetainfo['html'] );
			$captcha->addFormInformationToOutput( $out, $formMetainfo );
			// For grep: fancycaptcha-specialpage, questycaptcha-specialpage
			$captchaForm = $captcha->getMessage( 'specialpage' ) . $formInformation['html'];
		}

		return $captchaForm;
	}
}
