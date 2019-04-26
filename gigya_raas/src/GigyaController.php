<?php
	/**
	 * @file
	 * Contains \Drupal\gigya\GigyaController.
	 */

	namespace Drupal\gigya_raas;

	use Drupal\Core\Ajax\AjaxResponse;
	use Drupal\Core\Ajax\AlertCommand;
	use Drupal\Core\Ajax\InvokeCommand;
	use Drupal\Core\Controller\ControllerBase;
	use Drupal\user\Entity\User;
	use Drupal\user\UserInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Drupal\gigya\Helper\GigyaHelper;

	/**
	 * Returns responses for Editor module routes.
	 */
	class GigyaController extends ControllerBase
	{
		/** @var GigyaHelper */
		protected $helper;

		/**
		 * Construct method.
		 *
		 * @param bool $helper
		 */
		public function __construct($helper = FALSE) {
			if ($helper == FALSE)
			{
				$this->helper = new GigyaHelper();
			}
			else
			{
				$this->helper = $helper;
			}
		}

		/**
		 * Process Gigya RaaS login.
		 *
		 * @param \Symfony\Component\HttpFoundation\Request $request
		 *   The incoming request object.
		 *
		 * @return \Drupal\Core\Ajax\AjaxResponse
		 *   The Ajax response
		 *
		 * @throws \Drupal\Core\Entity\EntityStorageException
		 */
		public function gigyaRaasProfileAjax(Request $request) {
			$gigya_data = $request->get('gigyaData');
			if ($gigyaUser = $this->helper->validateUid($gigya_data['UID'], $gigya_data['UIDSignature'], $gigya_data['signatureTimestamp']))
			{
				if ($user = $this->helper->getUidByUUID($gigyaUser->getUID()))
				{
					if ($unique_email = $this->helper->checkEmailsUniqueness($gigyaUser, $user->id()))
					{
						if ($user->mail !== $unique_email)
						{
							$user->setEmail($unique_email);
							$user->save();
						}
					}
					$this->helper->processFieldMapping($gigyaUser, $user);
					\Drupal::moduleHandler()->alter('gigya_profile_update', $gigyaUser, $user);
					$user->save();
				}
			}
			return new AjaxResponse();
		}

		/**
		 * Raw method for processing field mapping. Currently an alias for gigyaRaasProfileAjax, but could be modified in the future.
		 *
		 * @param \Symfony\Component\HttpFoundation\Request $request
		 *
		 * @return \Drupal\Core\Ajax\AjaxResponse
		 * @throws \Drupal\Core\Entity\EntityStorageException
		 */
		public function gigyaRaasProcessFieldMapping(Request $request) {
			return $this->gigyaRaasProfileAjax($request);
		}

		/**
		 * @param Request $request      The incoming request object.
		 *
		 * @return bool|AjaxResponse    The Ajax response
		 *
		 * @throws \Drupal\Core\Entity\EntityStorageException
		 */
		public function gigyaRaasLoginAjax(Request $request) {
			if (\Drupal::currentUser()->isAnonymous())
			{
				global $raas_login;
				$err_msg = FALSE;
				$sig_timestamp = $request->get('sig_timestamp');
				$guid = $request->get('uid');
				$uid_sig = $request->get('uid_sig');

				$login_redirect = \Drupal::config('gigya_raas.settings')->get('gigya_raas.login_redirect');
				$logout_redirect = \Drupal::config('gigya_raas.settings')->get('gigya_raas.logout_redirect');

				$base_path = base_path();
				$redirect_path = ($base_path === '/') ? '/' : $base_path . '/';
				if (substr($login_redirect, 0, 4) !== 'http')
				{
					$login_redirect = $redirect_path . $login_redirect;
				}
				if (substr($logout_redirect, 0, 4) !== 'http')
				{
					$logout_redirect = $redirect_path . $logout_redirect;
				}

				$response = new AjaxResponse();

				if ($gigyaUser = $this->helper->validateUid($guid, $uid_sig, $sig_timestamp))
				{
					if (empty($gigyaUser->getLoginIDs['emails']))
					{
						$err_msg = $this->t(
							'Email address is required by Drupal and is missing, please contact the site administrator.'
						);
						$this->helper->saveUserLogoutCookie();
					}
					else
					{
						/** @var UserInterface $user */
						$user = $this->helper->getUidByUUID($gigyaUser->getUID());
						if ($user)
						{
							/** if a user has the a permission of bypass gigya raas (admin user)
							 *  they can't login via gigya
							 */
							if ($user->hasPermission('bypass gigya raas'))
							{
								\Drupal::logger('gigya_raas')->notice(
									"User with email " . $user->getEmail()
									. " that has 'bypass gigya raas' permission tried to login via gigya"
								);
								$this->helper->saveUserLogoutCookie();
								$err_msg = $this->t(
									"Oops! Something went wrong during your login/registration process. Please try to login/register again."
								);
								$response->addCommand(new AlertCommand($err_msg));

								return $response;
							}
							if ($unique_email = $this->helper->checkEmailsUniqueness($gigyaUser, $user->id()))
							{
								if ($user->mail !== $unique_email)
								{
									$user->setEmail($unique_email);
									$user->save();
								}
							}
							else
							{
								$this->helper->saveUserLogoutCookie();
								$err_msg = $this->t("Email already exists");
								$response->addCommand(new AlertCommand($err_msg));
								return $response;
							}
							/* Set global variable so we would know the user as logged in
							   RaaS in other functions down the line.*/
							$raas_login = TRUE;

							/* Log the user in */
							$this->helper->processFieldMapping($gigyaUser, $user);
							$this->gigyaRaasExtCookieAjax($request, $raas_login);
							$user->save();
							user_login_finalize($user);
						}
						else
						{
							$uids = $this->helper->getUidByMails($gigyaUser->getLoginIds['emails']);
							if (!empty($uids))
							{
								\Drupal::logger('gigya_raas')->warning(
									"User with uid " . $guid . " that already exists tried to register via gigya"
								);
								$this->helper->saveUserLogoutCookie();
								$err_msg = $this->t(
									"Oops! Something went wrong during your login/registration process. Please try to login/register again."
								);
								$response->addCommand(new AlertCommand($err_msg));
								return $response;
							}
							if ($unique_email = $this->helper->checkEmailsUniqueness($gigyaUser, 0))
							{
								$email = $unique_email;
							}
							else
							{
								$this->helper->saveUserLogoutCookie();
								$err_msg = $this->t("Email already exists");
								$response->addCommand(new AlertCommand($err_msg));
								return $response;
							}
							$gigya_user_name = $gigyaUser->getProfile()->getUsername();
							$uname = !empty($gigya_user_name) ? $gigyaUser->getProfile()->getUsername()
								: $gigyaUser->getProfile()->getFirstName();
							if (!$this->helper->getUidByName($uname))
							{
								$username = $uname;
							}
							else
							{
								/* If user name is taken use first name if it is not empty. */
								$gigya_firstname = $gigyaUser->getProfile()->getFirstName();
								if (!empty($gigya_firstname)
									&& (!$this->helper->getUidByName(
										$gigyaUser->getProfile()->getFirstName()
									))
								)
								{
									$username = $gigyaUser->getProfile()->getFirstName();
								}
								else
								{
									/* When all fails add unique id  to the username so we could register the user. */
									$username = $uname . '_' . uniqid();
								}
							}

							$user = User::create(
								array('name' => $username, 'pass' => user_password(), 'status' => 1, 'mail' => $email)
							);
							$user->save();
							$this->helper->processFieldMapping($gigyaUser, $user);

							/* Allow other modules to modify the data before user is created in drupal database (create user hook). */
							\Drupal::moduleHandler()->alter('gigya_raas_create_user', $gigyaUser, $user);
							try
							{
								//@TODO: generate Unique user name.
								$user->save();
								$raas_login = true;
								$this->gigyaRaasExtCookieAjax($request, $raas_login);
								user_login_finalize($user);
							}
							catch (\Exception $e)
							{
								\Drupal::logger('gigya_raas')->notice('User with username: '.$username.' could not log in after registration. Exception: '.$e->getMessage());
								session_destroy();
								$err_msg = $this->t(
									"Oops! Something went wrong during your registration process. You are registered to the site but not logged-in. Please try to login again."
								);
								$this->helper->saveUserLogoutCookie();

								/* Post-logout redirect hook */
								\Drupal::moduleHandler()->alter('gigya_post_logout_redirect', $logout_redirect);
								$response->addCommand(new InvokeCommand(NULL, 'logoutRedirect', [$logout_redirect]));
							}
						}
					}
				}
				else
				{
					$this->helper->saveUserLogoutCookie();
          			\Drupal::logger('gigya_raas')->notice('Invalid user. Guid: '.$guid);
					$err_msg = $this->t(
						"Oops! Something went wrong during your login/registration process. Please try to login/register again."
					);
				}

				if ($err_msg !== FALSE)
				{
					$response->addCommand(new AlertCommand($err_msg));
				}
				else
				{
					/* Post-login redirect hook */
					\Drupal::moduleHandler()->alter('gigya_post_login_redirect', $login_redirect);
					$response->addCommand(new InvokeCommand(NULL, 'loginRedirect', [$login_redirect]));
				}

				return $response;
			}

			return false;
		}

		/**
		 * @param Request $request      The incoming request object.
		 *
		 * @return bool|AjaxResponse    The Ajax response
		 */
		public function gigyaRaasLogoutAjax(Request $request) {
			$logout_redirect = \Drupal::config('gigya_raas.settings')->get('gigya_raas.logout_redirect');

			/* Log out user in SSO */
			if (!empty(\Drupal::currentUser()->id()))
				user_logout();

			$base_path = base_path();
			$redirect_path = ($base_path === '/') ? '/' : $base_path . '/';
			if (substr($logout_redirect, 0, 4) !== 'http')
			{
				$logout_redirect = $redirect_path . $logout_redirect;
			}

			$response = new AjaxResponse();

			/* Post-logout redirect hook */
			\Drupal::moduleHandler()->alter('gigya_post_logout_redirect', $logout_redirect);
			$response->addCommand(new InvokeCommand(NULL, 'logoutRedirect', [$logout_redirect]));

			return $response;
		}

		/**
		 * Process gigya dynamic cookie request.
		 *
		 * @param \Symfony\Component\HttpFoundation\Request $request
		 *   The incoming request object.
		 * @param    boolean                                $login
		 *
		 * @return \Drupal\Core\Ajax\AjaxResponse
		 *   The Ajax response
		 */
		public function gigyaRaasExtCookieAjax(Request $request, $login = FALSE) {
			if ($this->shouldAddExtCookie($request, $login))
			{
				$gigya_conf = \Drupal::config('gigya.settings');
				$session_ttl = \Drupal::config('gigya_raas.settings')->get('gigya_raas.session_time');
				$api_key = $gigya_conf->get('gigya.gigya_api_key');
				$glt_cookie = $request->cookies->get('glt_' . $api_key);
				$token = (!empty(explode('|', $glt_cookie)[0])) ? explode('|', $glt_cookie)[0] : NULL;
				$now = $_SERVER['REQUEST_TIME'];
				$expiration = strval($now + $session_ttl);
				$helper = new GigyaHelper();

				$gltexp_cookie = $request->cookies->get('gltexp_' . $api_key);
				$gltexp_cookie_timestamp = explode('_', $gltexp_cookie)[0];
				if (empty($gltexp_cookie_timestamp) or (time() < $gltexp_cookie_timestamp))
				{
					if (!empty($token))
					{
						$session_sig = $this->calcDynamicSessionSig(
							$token, $expiration, $gigya_conf->get('gigya.gigya_application_key'),
							$helper->decrypt($gigya_conf->get('gigya.gigya_application_secret_key'))
						);
						setrawcookie('gltexp_' . $api_key, rawurlencode($session_sig), time() + (10 * 365 * 24 * 60 * 60), '/', $request->getHost());
					}
				}
			}
			return new AjaxResponse();
		}

		private function shouldAddExtCookie($request, $login) {
			if ("dynamic" != \Drupal::config('gigya_raas.settings')->get('gigya_raas.session_type'))
			{
				return false;
			}

			if ($login)
			{
				return true;
			}

			$current_user = \Drupal::currentUser();
			if ($current_user->isAuthenticated() && !$current_user->hasPermission('bypass gigya raas'))
			{
				$gigya_conf = \Drupal::config('gigya.settings');
				$api_key = $gigya_conf->get('gigya.gigya_api_key');
				$gltexp_cookie = $request->cookies->get('gltexp_' . $api_key);
				return !empty($gltexp_cookie);
			}
			return true;
		}

		private function calcDynamicSessionSig($token, $expiration, $userKey, $secret) {
			$unsignedExpString = utf8_encode($token . "_" . $expiration . "_" . $userKey);
			$rawHmac = hash_hmac("sha1", utf8_encode($unsignedExpString), base64_decode($secret), TRUE);
			$sig = base64_encode($rawHmac);
			return $expiration . '_' . $userKey . '_' . $sig;
		}

	}