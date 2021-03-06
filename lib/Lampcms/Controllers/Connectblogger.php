<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is lisensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 * 	  the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website\'s Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attibutes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2011 (or current year) ExamNotes.net inc.
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */


namespace Lampcms\Controllers;

use Lampcms\WebPage;
use Lampcms\Responder;
use Lampcms\Modules\Blogger\Blogs;
use Lampcms\Request;

/**
 * Controller for displaying "Connect blogger account"
 * page and processing Blogger OAuth authentication
 * to add Blogger oauth token/secret to user's account
 *
 *
 * @author Dmitri Snytkine
 *
 */
class Connectblogger extends WebPage
{

	const REQUEST_TOKEN_URL = 'https://www.google.com/accounts/OAuthGetRequestToken?scope=http://www.blogger.com/feeds/&oauth_callback=';

	const AUTHORIZE_URL = 'https://www.google.com/accounts/OAuthAuthorizeToken';

	const ACCESS_TOKEN_URL = 'https://www.google.com/accounts/OAuthGetAccessToken';

	const GET_BLOGS = 'https://www.blogger.com/feeds/default/blogs';


	/**
	 * Array of Blogger's
	 * oauth_token and oauth_token_secret
	 *
	 * @var array
	 */
	protected $aAccessToken = array();


	/**
	 * Object php OAuth
	 *
	 * @var object of type php OAuth
	 * must have oauth extension for this
	 */
	protected $oAuth;


	protected $bInitPageDoc = false;


	/**
	 * Configuration of Blogger API
	 * this is array of values Blogger section
	 * in !config.ini
	 *
	 * @var array
	 */
	protected $aConfig = array();


	/**
	 * Array of User's Blogger blogs
	 * User can have more than one blog on Blogger
	 *
	 * @var array
	 */
	protected $aBlogs;


	protected $callback = '/index.php?a=connectblogger';

	/**
	 * The main purpose of this class is to
	 * generate the oAuth token
	 * and then redirect browser to twitter url with
	 * this unique token
	 *
	 * No actual page generation will take place
	 *
	 * @see classes/WebPage#main()
	 */
	protected function main(){
		$Request = $this->Registry->Request;
		d('Request: '.var_export($Request, true));
		
		if('1' == $Request->get('blogselect', 's', '')){
			d('cp');
			
			return $this->selectBlog();
		}

		$this->callback = $this->Registry->Ini->SITE_URL.$this->callback;
		d('$this->callback: '.$this->callback);

		if(!extension_loaded('oauth')){
			throw new \Exception('Unable to use Blogger API because OAuth extension is not available');
		}

		$this->aConfig = $this->Registry->Ini['BLOGGER'];

		try {
			$this->oAuth = new \OAuth($this->aConfig['OAUTH_KEY'], $this->aConfig['OAUTH_SECRET'], OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
			$this->oAuth->enableDebug();
		} catch(\OAuthException $e) {
			e('OAuthException: '.$e->getMessage());

			throw new \Exception('Something went wrong during authorization. Please try again later'.$e->getMessage());
		}


		/**
		 * If this is start of dance then
		 * generate token, secret and store them
		 * in session and redirect to Blogger authorization page
		 */
		if(empty($_SESSION['blogger_oauth']) || empty($this->Request['oauth_token'])){
			/**
			 * Currently Blogger does not handle "Deny" response of user
			 * too well - they just redirect back to this url
			 * without any clue that user declined to authorize
			 * our application.
			 */
			$this->step1();
		} else {
			$this->step2();
		}
	}


	/**
	 * Process the submitted "select default blog" form
	 * This form is displayed to user when we detect that
	 * user has more than one blog on Blogger, in which
	 * case user is asked to pick one blog that will
	 * be connected to this account
	 *
	 *
	 * @throws \Exception if user does not have any blogs, which
	 * is not really possible, so this would be totally unexpected
	 */
	protected function selectBlog(){
		if ('POST' !== Request::getRequestMethod() ) {
			throw new \Lampcms\Exception('POST method required');
		}

		\Lampcms\Forms\Form::validateToken($this->Registry);

		$a = $this->Registry->Viewer->getBloggerBlogs();
		d('$a: '.print_r($a, 1));

		if(empty($a)){
			throw new \Exception('No blogs found for this user');
		}

		$selectedID = (int)substr($this->Request->get('blog'), 4);
		d('$selectedID: '. $selectedID);

		/**
		 * Pull the selected blog from array of user blogs
		 *
		 * @var unknown_type
		 */
		$aBlog = \array_splice($a, $selectedID, 1);
		d('$aBlog: '.print_r($aBlog, 1));
		d('a now: '.print_r($a, 1));

		/**
		 * Now stick this blog to the
		 * beginning of array. It will become
		 * the first element, pushing other blogs
		 * down in the array
		 * User's "Connected" blog is always
		 * the first blog in array!
		 *
		 */
		\array_unshift($a, $aBlog[0]);
		d('a after unshift: '.print_r($a, 1));

		$this->Registry->Viewer->setBloggerBlogs($a);
		/**
		 * Set b_bg to true which will result
		 * in "Post to Blogger" checkbox to
		 * be checked. User can uncheck in later
		 */
		$this->Registry->Viewer['b_bg'] = true;
		$this->Registry->Viewer->save();
		$this->closeWindow();
	}


	/**
	 * Generate oAuth request token
	 * and redirect to Blogger for authentication
	 *
	 * @return object $this
	 *
	 * @throws Exception in case something goes wrong during
	 * this stage
	 */
	protected function step1(){

		try {
			// State 0 - Generate request token and redirect user to Blogger to authorize
			$url =
			$_SESSION['blogger_oauth'] = $this->oAuth->getRequestToken(self::REQUEST_TOKEN_URL.$this->callback);

			d('$_SESSION[\'blogger_oauth\']: '.print_r($_SESSION['blogger_oauth'], 1));
			if(!empty($_SESSION['blogger_oauth']) && !empty($_SESSION['blogger_oauth']['oauth_token'])){

				Responder::redirectToPage(self::AUTHORIZE_URL.'?oauth_token='.$_SESSION['blogger_oauth']['oauth_token'].'&oauth_callback='.$this->callback);
			} else {
				/**
				 * Here throw regular Exception, not Lampcms\Exception
				 * so that it will be caught ONLY by the index.php and formatted
				 * on a clean page, without any template
				 */
				throw new \Exception("Failed fetching request token, response was: " . $this->oAuth->getLastResponse());
			}
		} catch(\OAuthException $e) {
			e('OAuthException: '.$e->getMessage().' '.print_r($e, 1));

			throw new \Exception('Something went wrong during authorization. Please try again later'.$e->getMessage());
		}

		return $this;
	}


	/**
	 * Step 2 in oAuth process
	 * this is when Blogger redirected the user back
	 * to our callback url, which calls this controller
	 * @return object $this
	 *
	 * @throws Exception in case something goes wrong with oAuth class
	 */
	protected function step2(){

		try {
			/**
			 * This is a callback (redirected back from Blogger page
			 * after user authorized us)
			 * In this case we must: create account or update account
			 * in USER table
			 * Re-create oViewer object
			 * send cookie to remember user
			 * and then send out HTML with js instruction to close the popup window
			 */
			d('Looks like we are at step 2 of authentication. Request: '.print_r($_REQUEST, 1));

			/**
			 * @todo check first to make sure we do have oauth_token
			 * on REQUEST, else close the window
			 */

			$this->oAuth->setToken($this->Request['oauth_token'], $_SESSION['blogger_oauth']['oauth_token_secret']);
			$ver = $this->Registry->Request['oauth_verifier'];
			d(' $ver: '.$ver);
			$url = self::ACCESS_TOKEN_URL.'?oauth_verifier='.$ver;
			d('url: '.$url);

			$this->aAccessToken = $this->oAuth->getAccessToken(self::ACCESS_TOKEN_URL);
			d('$this->aAccessToken: '.print_r($this->aAccessToken, 1));

			unset($_SESSION['blogger_oauth']);

			$this->oAuth->setToken($this->aAccessToken['oauth_token'], $this->aAccessToken['oauth_token_secret']);

			/**
			 * Now getUserBlogs
			 * Then if user has more than one blog
			 * display a form with "select blog"
			 * + description about it
			 *
			 * Make sure to run connect() first so that oViewer['Blogger']
			 * element will be created and will have all user blogs
			 *
			 * Else - user has just one blog then close Window!
			 *
			 */
			d('cp');
			$this->getUserBlogs()->connect();
			d('cp');

			/**
			 * If user has more than one blog
			 * then show special form
			 */
			if(count($this->aBlogs) > 1){
				d('User has more than one blog, generating "select blog" form');
				$form = $this->makeBlogSelectionForm();
				d('$form: '.$form);
				echo(Responder::makeErrorPage($form));
				throw new \OutOfBoundsException;
			} else {
				d('User has one Blogger blog, using it now');
				/**
				 * Set flag to session indicating that user just
				 * connected Blogger Account
				 */
				$this->Registry->Viewer['b_bg'] = true;
				$this->closeWindow();
			}

		} catch(\OAuthException $e) {
			$aDebug = $this->oAuth->getLastResponseInfo();
			/**
			 * Always check for response code first!
			 * it must be 201 or it's no good!
			 *
			 * Also check the 'url' part of it
			 * if it does not match url you used
			 * in request then it was redirected!
			 */
			e('OAuthException: '.$e->getMessage().' in file '.$e->getFile().' on line: '.$e->getLine(). ' Debug: '.print_r($aDebug, 1));

			$err = 'Something went wrong during authorization. Please try again later. '.$e->getMessage();
			throw new \Exception($err);
		}

		return $this;
	}


	/**
	 *
	 * Make html form that asks
	 * a user to select one blog from the
	 * list of all user's blogs on Blogger
	 * 
	 * @return string html of the form
	 */
	protected function makeBlogSelectionForm(){
		/**
		 * @todo Translate string
		 */
		$label = 'You have more than one blog on Blogger.<br>
			 Please select one blog that will be connected to this account.<br>
			 <br>When you select the "Post to Blogger" option, your<br>
			 Question or Answer will be posted to this blog.';

		/**
		 * @todo Translate string
		 */
		$save = 'Save';
		$token = \Lampcms\Forms\Form::generateToken();
		$options = '';
		$tpl = '<option value="blog%s">%s</option>';
		foreach($this->aBlogs as $id => $blog){
			$options .= sprintf($tpl, $id, $blog['title']);
		}

		$vars = array(
		'token' => $token, 
		'options' => $options, 
		'label' => $label, 
		'save' => $save,
		'a' => 'connectblogger');

		return \tplTumblrblogs::parse($vars);
	}


	/**
	 * Add element [tumblr] to Viewer object
	 * this element is array with 2 keys: tokens
	 * and blogs - both are also arrays
	 *
	 * @return object $this
	 */
	protected function connect(){

		$this->Registry->Viewer['blogger'] = array('tokens' => $this->aAccessToken, 'blogs' => $this->aBlogs);
		$this->Registry->Viewer->save();

		return $this;
	}


	/**
	 * Return html that contains JS window.close code
	 * and nothing else
	 *
	 * @todo instead of just closing window
	 * can show a small form with pre-populated
	 * text to be posted to user's blog,
	 * for example "I just connected this blog
	 * to my account on SITE_NAME so I can
	 * automatically send some Questions and Answers
	 * to this blog. Check it out <- link
	 *
	 * And there will be 2 buttons Submit and Cancel
	 * Cancel will close window
	 *
	 * @return void
	 */
	protected function closeWindow(){

		$js = '';

		$tpl = '
		var myclose = function(){
		window.close();
		}
		if(window.opener){
		%s
		setTimeout(myclose, 100); // give opener window time to process login and cancell intervals
		}else{
			alert("This is not a popup window or opener window gone away");
		}';
		d('cp');

		$script = \sprintf($tpl, $js);

		$s = Responder::PAGE_OPEN. Responder::JS_OPEN.
		$script.
		Responder::JS_CLOSE.
		'<h2>You have successfully connected your Blogger Blog. You should close this window now</h2>'.

		Responder::PAGE_CLOSE;
		d('cp s: '.$s);
		echo $s;
		fastcgi_finish_request();
		exit;
	}


	/**
	 * Fetch xml from Blogger, parse it
	 * and generate array of $this->aBlogs
	 *
	 * @throws \Exception if something does not work
	 * as expected
	 *
	 * @return object $this
	 */
	protected function getUserBlogs(){
		$this->oAuth->fetch(self::GET_BLOGS);
		$res = $this->oAuth->getLastResponse();
		d('res: '.$res);
			
		$aDebug = $this->oAuth->getLastResponseInfo();
		/**
		 * Always check for response code first!
		 * it must be 201 or it's no good!
		 *
		 * Also check the 'url' part of it
		 * if it does not match url you used
		 * in request then it was redirected!
		 */
		d('debug: '.print_r($aDebug, 1));
		if(empty($res) || empty($aDebug['http_code']) || '200' != $aDebug['http_code']){
			$err = 'Unexpected Error parsing API response';

			throw new \Exception($err);
		}

		$oBlogs = new Blogs();
		$this->aBlogs = $oBlogs->getBlogs($res);

		return $this;
	}

}
