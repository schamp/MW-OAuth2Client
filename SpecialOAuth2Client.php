<?php
/**
 * SpecialOAuth2Client.php
 * Based on TwitterLogin by David Raison, which is based on the guideline published by Dave Challis at http://blogs.ecs.soton.ac.uk/webteam/2010/04/13/254/
 * @license: LGPL (GNU Lesser General Public License) http://www.gnu.org/licenses/lgpl.html
 *
 * @file SpecialOAuth2Client.php
 * @ingroup OAuth2Client
 *
 * @author Joost de Keijzer
 * @author Nischay Nahata for Schine GmbH
 *
 * Uses the OAuth2 library https://github.com/vznet/oauth_2.0_client_php
 *
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}

class SpecialOAuth2Client extends SpecialPage {

	private $_provider;

	/**
	 * Required settings in global $wgOAuth2Client
	 *
	 * $wgOAuth2Client['client']['id']
	 * $wgOAuth2Client['client']['secret']
	 * //$wgOAuth2Client['client']['callback_url'] // extension should know
	 *
	 * $wgOAuth2Client['configuration']['authorize_endpoint']
	 * $wgOAuth2Client['configuration']['access_token_endpoint']
	 * $wgOAuth2Client['configuration']['http_bearer_token']
	 * $wgOAuth2Client['configuration']['query_parameter_token']
	 * $wgOAuth2Client['configuration']['api_endpoint']
	 */
	public function __construct() {

		parent::__construct('OAuth2Client'); // ???: wat doet dit?
		global $wgOAuth2Client, $wgScriptPath;
		global $wgServer, $wgArticlePath;

		require __DIR__ . '/vendors/oauth2-client/vendor/autoload.php';

		$this->_provider = new \League\OAuth2\Client\Provider\GenericProvider([
			'clientId'                => $wgOAuth2Client['client']['id'],    // The client ID assigned to you by the provider
			'clientSecret'            => $wgOAuth2Client['client']['secret'],   // The client password assigned to you by the provider
			'redirectUri'             => $wgOAuth2Client['configuration']['redirect_uri'],
			'urlAuthorize'            => $wgOAuth2Client['configuration']['authorize_endpoint'],
			'urlAccessToken'          => $wgOAuth2Client['configuration']['access_token_endpoint'],
			'urlResourceOwnerDetails' => $wgOAuth2Client['configuration']['api_endpoint'],
			'scopes'                  => $wgOAuth2Client['configuration']['scopes']
		]);
	}

	// default method being called by a specialpage
	public function execute( $parameter ){
		$this->setHeaders();
		switch($parameter){
			case 'redirect':
				$this->_redirect();
			break;
			case 'callback':
				$this->_handleCallback();
			break;
			default:
				$this->_default();
			break;
		}

	}

	private function _redirect() {

		global $wgRequest, $wgOut;
		$wgRequest->getSession()->persist();
		$wgRequest->getSession()->set('returnto', $wgRequest->getVal( 'returnto' ));

		// Fetch the authorization URL from the provider; this returns the
		// urlAuthorize option and generates and applies any necessary parameters
		// (e.g. state).
		$authorizationUrl = $this->_provider->getAuthorizationUrl();

		// Get the state generated for you and store it to the session.
		$wgRequest->getSession()->set('oauth2state', $this->_provider->getState());
		$wgRequest->getSession()->save();

		// Redirect the user to the authorization URL.
		$wgOut->redirect( $authorizationUrl );
	}

	private function _handleCallback(){
		try {

			// Try to get an access token using the authorization code grant.
			$accessToken = $this->_provider->getAccessToken('authorization_code', [
				'code' => $_GET['code']
			]);
		} catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {

			// Failed to get the access token or user details.
			exit($e->getMessage());

		}

		$resourceOwner = $this->_provider->getResourceOwner($accessToken);
		$user = $this->_userHandling( $resourceOwner->toArray() );
		$user->setCookies();

		global $wgOut, $wgRequest;
		$title = null;
		$wgRequest->getSession()->persist();
		if( $wgRequest->getSession()->exists('returnto') ) {
			$title = Title::newFromText( $wgRequest->getSession()->get('returnto') );
			$wgRequest->getSession()->remove('returnto');
			$wgRequest->getSession()->save();
		}

		if( !$title instanceof Title || 0 > $title->mArticleID ) {
			$title = Title::newMainPage();
		}
		$wgOut->redirect( $title->getFullURL() );
		return true;
	}

	private function _default(){
		global $wgOAuth2Client, $wgOut, $wgUser, $wgScriptPath, $wgExtensionAssetsPath;
		$service_name = ( isset( $wgOAuth2Client['configuration']['service_name'] ) && 0 < strlen( $wgOAuth2Client['configuration']['service_name'] ) ? $wgOAuth2Client['configuration']['service_name'] : 'OAuth2' );

		$wgOut->setPagetitle( wfMessage( 'oauth2client-login-header', $service_name)->text() );
		if ( !$wgUser->isLoggedIn() ) {
			$wgOut->addWikiMsg( 'oauth2client-you-can-login-to-this-wiki-with-oauth2', $service_name );
			$wgOut->addWikiMsg( 'oauth2client-login-with-oauth2', $this->getTitle( 'redirect' )->getPrefixedURL(), $service_name );

		} else {
			$wgOut->addWikiMsg( 'oauth2client-youre-already-loggedin' );
		}
		return true;
	}

	protected function _userHandling( $response ) {
		global $wgOAuth2Client, $wgAuth, $wgRequest;

		$response = array_key_exists('user', $response) ? $response['user'] : $response;

		$username = $response[$wgOAuth2Client['configuration']['username']];
		$email = $response[$wgOAuth2Client['configuration']['email']];

		// FIXME: check if user is allowed to create account

		$user = User::newFromName($email);
		if (!$user) {
			throw new MWException('Could not create user with email:' . $email);
			die();
		}
		$user->setRealName($username);
		$user->setEmail($email);
		$user->load();
		if ( !( $user instanceof User && $user->getId() ) ) {
			$user->addToDatabase();
			// MediaWiki recommends below code instead of addToDatabase to create user but it seems to fail.
			$authManager = MediaWiki\Auth\AuthManager::singleton();
			$authManager->autoCreateUser( $user, MediaWiki\Auth\AuthManager::AUTOCREATE_SOURCE_SESSION );
		}
		$user->setToken();

		// Add to Admin if `admin_trigger` criteria matches
		if (array_key_exists('admin_trigger', $wgOAuth2Client['configuration'])) {
			$aKey = array_keys($wgOAuth2Client['configuration']['admin_trigger'])[0];
			$aVal = $wgOAuth2Client['configuration']['admin_trigger'][$aKey];
			if ($response[$aKey] == $aVal) {
				$user->addGroup('sysop');
			}
		}

		// Add to user groups via map, if provided
		if (array_key_exists('group_map', $wgOAuth2Client['configuration'])) {
			$groupMap = $wgOAuth2Client['configuration']['group_map'];
			foreach ($wgOAuth2Client['configuration']['group_map'] as $gKey => $gVal) {
				if ($response[$gKey] === true) {
					$user->addGroup($gVal);
				}
			}
		}

		// Add user to groups via group key, if provided
		// update the groups to match the groups from oauth
		// 'groups' must be provided
		// if 'group_prefix' is not provided, 'oauth_' will be used by default
		if (array_key_exists('groups', $wgOAuth2Client['configuration'])) {
			$group_prefix = 'oauth_';
			if (array_key_exists('group_prefix', $wgOAuth2Client['configuration'])) {
				$group_prefix = $wgOAuth2Client['configuration']['group_prefix'];
			}
			if (!$group_prefix) {
				throw new MWException("\$wgOAuth2Client['configuration']['group_prefix'] must not be empty!");
			}

			$add_prefix = function($s) use ($group_prefix) {
				return $group_prefix.$s;
			};
			$oauth_groups = array_map($add_prefix, $response[$wgOAuth2Client['configuration']['groups']]);

			$existing_groups = $user->getGroups();

			# remove groups that are no longer valid
			foreach ($existing_groups as $group) {
				if (strpos($group, $group_prefix, 0) === 0 && !in_array($group, $oauth_groups, true)) {
					$user->removeGroup($group);
				}
			}

			# add missing groups
			foreach ($oauth_groups as $group) {
				if (!in_array($group, $existing_groups, true)) {
					$user->addGroup($group);
				}
			}
		}

		// Setup the session
		$wgRequest->getSession()->persist();
		$user->setCookies();
		$this->getContext()->setUser( $user );
		$user->saveSettings();
		global $wgUser;
		$wgUser = $user;
		$sessionUser = User::newFromSession($this->getRequest());
		$sessionUser->load();
		return $user;
	}

}
