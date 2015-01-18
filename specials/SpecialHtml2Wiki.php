<?php
/**
 * Import/Upload SpecialPage for Html2Wiki extension
 *
 * @see https://www.mediawiki.org/wiki/Manual:Special_pages
 * @file
 * @ingroup Extensions
 * @package Html2Wiki
 * 
 * @todo implement style guide https://www.mediawiki.org/wiki/Design/Living_style_guide
 */

class SpecialHtml2Wiki extends SpecialPage {
    
	/** @var string The HTML we want to turn into wiki text */
    private $mContent;
    private $mContentRaw;  // the exact contents which were uploaded
    private $mContentTidy; // Raw afer it's passed through tidy
    private $mFile;        // The (input) HTML file
    /** @var string The (original) name of the uploaded file */
    private $mFilename;
    /** @var int The size, in bytes, of the uploaded file. */
    private $mFilesize;
    private $mSummary;
    
    private $mIsTidy;      // @var bool true once passed through tidy
    private $mTidyErrors;  // the error output of tidy
    private $mTidyConfig;  // the configuration we want to use for tidy.
            


    /** @todo review and cull the properties that we use here
     * These properties were copied from Special:Upload assuming they'd be 
     * applicable to our use case.
     */
	/** @var WebRequest|FauxRequest The request this form is supposed to handle */
	public $mRequest;
	public $mSourceType;

	/** @var UploadBase */
	public $mUpload;

	/** @var LocalFile */
	public $mLocalFile;
	public $mUploadClicked;

	/** User input variables from the "description" section **/

	/** @var string The requested target file name */
	public $mDesiredDestName;
	public $mComment;
	public $mLicense; // don't know if we'll bother with this

	/** User input variables from the root section **/

	public $mIgnoreWarning;
	public $mWatchthis;
	public $mCopyrightStatus;
	public $mCopyrightSource;

	/** Hidden variables **/

	public $mDestWarningAck;

	/** @var bool The user followed an "overwrite this file" link */
	public $mForReUpload;

	/** @var bool The user clicked "Cancel and return to upload form" button */
	public $mCancelUpload;
	public $mTokenOk;

	/** @var bool Subclasses can use this to determine whether a file was uploaded */
	public $mUploadSuccessful = false;

	/** Text injection points for hooks not using HTMLForm **/
	public $uploadFormTextTop;
	public $uploadFormTextAfterSummary;

    
    
    protected function loadRequest() {
        $this->mRequest = $request = $this->getRequest();
        // get the value from the form, or use the default defined in the language messages

		//$commentDefault = wfMessage( 'html2wiki-comment' )->inContentLanguage()->plain();
        $commentDefault = wfMessage( 'html2wiki-comment' )->inContentLanguage()->parse();
        $this->mComment = $request->getText('log-comment', $commentDefault);
    }
    
	/**
	 * Initialize instance variables from request and create an Upload handler
     * @todo review and cull the methods that we use here
     * This method was copied from Special:Upload assuming it would be 
     * applicable to our use case.
	 
	protected function loadRequest() {
		$this->mRequest = $request = $this->getRequest();
		$this->mSourceType = $request->getVal( 'wpSourceType', 'file' );
        // @todo What can we use besides UploadBase because we don't want to store file types: HTML and zip?
        // 
		$this->mUpload = UploadBase::createFromRequest( $request );
		$this->mUploadClicked = $request->wasPosted()
			&& ( $request->getCheck( 'wpUpload' )
				|| $request->getCheck( 'wpUploadIgnoreWarning' ) );

		// Guess the desired name from the filename if not provided
		$this->mDesiredDestName = $request->getText( 'wpDestFile' );
		if ( !$this->mDesiredDestName && $request->getFileName( 'wpUploadFile' ) !== null ) {
			$this->mDesiredDestName = $request->getFileName( 'wpUploadFile' );
		}
		$this->mLicense = $request->getText( 'wpLicense' );

		$this->mDestWarningAck = $request->getText( 'wpDestFileWarningAck' );
		$this->mIgnoreWarning = $request->getCheck( 'wpIgnoreWarning' )
			|| $request->getCheck( 'wpUploadIgnoreWarning' );
		$this->mWatchthis = $request->getBool( 'wpWatchthis' ) && $this->getUser()->isLoggedIn();
		$this->mCopyrightStatus = $request->getText( 'wpUploadCopyStatus' );
		$this->mCopyrightSource = $request->getText( 'wpUploadSource' );

		$this->mForReUpload = $request->getBool( 'wpForReUpload' ); // updating a file

		$commentDefault = '';
		$commentMsg = wfMessage( 'upload-default-description' )->inContentLanguage();
		if ( !$this->mForReUpload && !$commentMsg->isDisabled() ) {
			$commentDefault = $commentMsg->plain();
		}
		$this->mComment = $request->getText( 'wpUploadDescription', $commentDefault );

		$this->mCancelUpload = $request->getCheck( 'wpCancelUpload' )
			|| $request->getCheck( 'wpReUpload' ); // b/w compat

		// If it was posted check for the token (no remote POST'ing with user credentials)
		$token = $request->getVal( 'wpEditToken' );
		$this->mTokenOk = $this->getUser()->matchEditToken( $token );

		$this->uploadFormTextTop = '';
		$this->uploadFormTextAfterSummary = '';
	}
*/
    
    /**
	 * Constructor : initialise object
	 * Get data POSTed through the form and assign them to the object
	 * @param WebRequest $request Data posted.
	 * We'll use the parent's constructor to instantiate the name but not perms
     * 
     * @todo We need to check for $wgEnableWriteAPI=true (default) because if it's not
     * then our extension will not be able to do it's work.  There are two possible
     * errors:
     * noapiwrite: Editing of this wiki through the API is disabled. Make sure the $wgEnableWriteAPI=true; statement is included in the wiki's LocalSettings.php file
     * writeapidenied: You're not allowed to edit this wiki through the API
     * 
     * The first is clear enough to the user what they have to do to fix it.
     * The second is that they are not configured properly in the correct group
     */
    public function __construct($request = null) {
		$name='Html2Wiki';
        parent::__construct($name); 
        // we might want to add rights here, or else do it in a method called in exectute
        //parent::__construct('Import Html', array('upload', 'reupload');
    }
    /**
     * Override the parent to set where the special page appears on Special:SpecialPages
     * 'other' is the default, so you do not need to override if that's what you want.
     * Specify 'media' to use the specialpages-group-media system interface 
     * message, which translates to 'Media reports and uploads' in English.
     * 
     * @return string
     */
    function getGroupName() {
		return 'media';
    }

    /**
     * Shows the page to the user.
     * @param string $sub: The subpage string argument (if any).
     *  [[Special:HelloWorld/subpage]].
     */
    public function execute($sub) {
		/**
		 * @since 1.23 we can create our own Config object
		 * @link https://www.mediawiki.org/wiki/Manual:Configuration_for_developers
		 
		$wgConfigRegistry['html2wiki'] = 'GlobalVarConfig::newInstance';
		// Now, whenever you want your config object
		// $conf = ConfigFactory::getDefaultInstance()->makeConfig( 'html2wiki' );
		*/
		
        $out = $this->getOutput();

        $out->setPageTitle($this->msg('html2wiki-title'));
		
		/**
		 * Restrict access to the importing of content.
		 * Use the same approach as Special:Import since if you're
		 * allowed to import wiki content, we'll also allow you to import
		 * HTML content.
		 */
		$user = $this->getUser();
/** @todo turn on this permission check
		if ( !$user->isAllowedAny( 'import', 'importupload' ) ) {
			throw new PermissionsError( 'import' );
		}
*/
		// Even without the isAllowsedAny check, the anonymous user sees
		// 'No transwiki import sources have been defined and direct history uploads are disabled.'
		
		# @todo Allow Title::getUserPermissionsErrors() to take an array
		# @todo FIXME: Title::checkSpecialsAndNSPermissions() has a very wierd expectation of what
		# getUserPermissionsErrors() might actually be used for, hence the 'ns-specialprotected'
		$errors = wfMergeErrorArrays(
			$this->getPageTitle()->getUserPermissionsErrors(
				'import', $user, true,
				array( 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' )
			),
			$this->getPageTitle()->getUserPermissionsErrors(
				'importupload', $user, true,
				array( 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' )
			)
		);

		if ( $errors ) {
			throw new PermissionsError( 'import', $errors );
		}
		// from parent, throw an error if the wiki is in read-only mode
		$this->checkReadOnly();
		$request = $this->getRequest();
		if ( $request->wasPosted() && $request->getVal( 'action' ) == 'submit' ) {
            $this->loadRequest();
			$this->doImport();
		} else {
		$this->showForm();
        }

    }

    
    /**
     * Upload user nominated file
     * Populate $this->mContent and $this->mFilename
     * Should probably also populate size type and mimetype
     */
    private function doUpload() {
        $out = $this->getOutput();
//        global $wgMaxUploadSize; // this is the old way
        $wgMaxUploadSize = $this->getConfig()->get('MaxUploadSize');
        try {
            // Undefined | Multiple Files | $_FILES Corruption Attack
            // If this request falls under any of them, treat it invalid.
            if (
                !isset($_FILES['userfile']['error']) ||
                is_array($_FILES['userfile']['error'])
            ) {
                throw new RuntimeException('Invalid parameters.');
            }
            // Check $_FILES['userfile']['error'] value.
            switch ($_FILES['userfile']['error']) {
                case UPLOAD_ERR_OK: // Value: 0; There is no error, the file uploaded with success.
                    break;
                case UPLOAD_ERR_INI_SIZE: 
                    // Value: 1; The uploaded file exceeds the upload_max_filesize directive in php.ini.
                    throw new RuntimeException('Exceeded filesize limit. Check upload_max_filesize in php.ini.');
                case UPLOAD_ERR_FORM_SIZE:
                    // Value: 2; The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
                    throw new RuntimeException('Exceeded filesize limit in form.');
                case UPLOAD_ERR_PARTIAL:
                    throw new RuntimeException('The uploaded file was only partially uploaded.');
                case UPLOAD_ERR_NO_FILE: // Value: 4; No file was uploaded.
                    throw new RuntimeException('No file sent.');
                default:
                    throw new RuntimeException('Unhandled error: ' . $_FILES['userfile']['error'] . ' in ' . __METHOD__);
            }
            // You should also check filesize here. 
            if ($_FILES['userfile']['size'] > $wgMaxUploadSize['*']) {
                throw new RuntimeException('Exceeded filesize limit defined as ' . $wgMaxUploadSize['*'] . '.');
            }
            /*
            // DO NOT TRUST $_FILES['userfile']['type'] VALUE !!
            // Check MIME Type by yourself.
             * except that we don't even care what is supplied by the client
             * We're going to process the files
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (false === $ext = array_search(
                $finfo->file($_FILES['userfile']['tmp_name']),
                array(
                    'text/html'
                ),
                true
            )) {
                throw new RuntimeException('Invalid file format.');
            }
            */
            // You should name it uniquely.
            // DO NOT USE $_FILES['userfile']['name'] WITHOUT ANY VALIDATION !!
            // On this example, obtain safe unique name from its binary data.
            /* We don't even need to move the file.  We WANT the upload to be discarded
             * and it will be after upload if we don't move it from the tmp_name
             * So, we can process it, and only use the result, while PHP destroys
             * the original source
             * 
            if (!move_uploaded_file(
                $_FILES['userfile']['tmp_name'],
                sprintf('./uploads/%s.%s',
                    sha1_file($_FILES['userfile']['tmp_name']),
                    $ext
                )
            )) {
                throw new RuntimeException('Failed to move uploaded file.');
            }
            */
            if (!is_uploaded_file($_FILES['userfile']['tmp_name'])) {
                throw new RuntimeException('Possible file upload attack.');
            }
        } catch (RuntimeException $e) {
            $out->wrapWikiMsg(
                "<p class=\"error\">\n$1\n</p>",
                array( 'html2wiki_uploaderror', $e->getMessage() )
            );
            return false;
        }
        // Don't do this because the file goes away
        //$this->mFile = $_FILES['userfile']['tmp_name'];
        $this->mFilename = $_FILES['userfile']['name'];
        $this->mContentRaw = $this->mContent = file_get_contents($_FILES['userfile']['tmp_name']);
        $this->mFilesize = $_FILES['userfile']['size'];
        return true;
    }
    
    private function doLocalFile($file) {
        $out = $this->getOutput();
        if(!file_exists($file)){
            $out->wrapWikiMsg(
                "<p class=\"error\">\n$1\n</p>",
                array( 'html2wiki_filenotfound', $file )
            );
            return false;
        }
        $this->mFile = $file;
        $this->mFilename = basename($file);
        $this->mContentRaw = file_get_contents($file);
        $this->mFilesize = filesize($file);
        return true;
    }
    
    /**
     * Not sure when we'll use this, but the intent was to create
     * an ajax interface to manipulate the file like wikEd
     */
    private function showRaw() {
        $out = $this->getOutput();
        $out->addModules( array('ext.Html2Wiki') ); // add our javascript and css
        //$this->mContent = $this->findBody();
        $out->addHTML('<div class="mw-ui-button-group">'
                . '<button class="mw-ui-button mw-ui-progressive" '
                . 'form="html2wiki-form" formmethod="post" id="h2wWand" name="h2wWand">'
                . '<img src="/w/extensions/Html2Wiki/modules/images/icons/wand.png" '
                . 'alt="wand"/></button>'
                . '</div><div style="clear:both"></div>');
        $out->addHTML('<div id="h2wContent">' . $this->mContentRaw . '</div>');
    }
    
    /**
     * displays $this->mContent (in a <source> block)
     * optionally passed through htmlentities() and nl2br()
     */
    private function showContent($showEscaped=false) {
        $out = $this->getOutput();
        $out->addModules( array('ext.Html2Wiki') ); // add our javascript and css
        
        // @todo Error or warn here if not $mIsTidy or $mIsPure
        
        if($showEscaped) {
            $escapedContent = $this->escapeContent();
            $out->addHTML('<div id="h2wLabel">Escaped File Contents:</div>');
            $out->addHTML('<div id="h2wContent">' . $escapedContent . '</div>');
        } else {
            $out->addHTML('<div id="h2wLabel">Original File Contents:</div>');
            // putting the original source into GeSHi makes it "safe" from the parser
            // but destroys any ability to act on the 'source' by manipulating this 
            // elements innerHTML
            $out->addWikiText('<source id="h2wContent" lang="html4strict">' . $this->mContent . '</source>');
        }
    }
    
    private function listFile() {
        $out = $this->getOutput();
        $out->addModules( array('ext.Html2Wiki') ); // add our javascript and css
        $out->addHTML('<ul class="mw-ext-Html2Wiki"><li>' . $this->mFilename . '</li></ul>');
    }
    
    /** Don't really need this because we're doing it with Tidy
     * Tidy will correct errors AND give us the body
     * @return type
     */
    private function findBody() {
        $out = $this->getOutput();
        $content = $this->mContentRaw;
        $pattern = '#<body[^>]*>(.*)</body>#';
        $foundBody = preg_match_all($pattern, $content, $matches);
        if ($foundBody && count($matches) === 2) {
            return $matches[1];
        } else {
            $out->wrapWikiMsg(
                "<p class=\"error\">\n$1\n</p>",
                array( 'html2wiki_multiple_body', $content )
            );
        }
    }

    private function escapeContent() {
        return nl2br(htmlentities($this->mContent, ENT_QUOTES | ENT_IGNORE, "UTF-8"));
    }
    
	/**
	 * Do the import which consists of three phases:
     * 1) Select and/or Upload user nominated files to a temporary area so that
     * we can then 
     * 2) pre-process, filter, and convert them to wikitext 
     * 3) Create the articles, and images
	 */
    private function doImport() {
        
        global  $wgOut;
        $wgOut->addModules( 'ext.Html2Wiki' );
        
        // We'll need to send the form input to parse.js
        // and the response/output will be wikitext.
        // We'll either be able to insert that programmatically
        // or use OutputPage->addWikiText() to make it appear in the page output for initial testing
        
        $tidyOpts = array(
            "drop-empty-paras" => 1,	
            "drop-font-tags" => 1, 
            "enclose-block-text" => 1, 
            "enclose-text" => 1, 
            "fix-backslash" => 1, 
            "fix-bad-comments" => 1, 
            "fix-uri" => 1, 
            "hide-comments" => 1, 
            "merge-divs" => 1, 
            "merge-spans" => 1, 
            "repeated-attributes" => "keep-first", 
            "show-body-only" => 1, 
            "show-errors" => 0, 
            "show-warnings" => 0, 

            "indent" => 0, 
            "wrap" => 120, 
            "tidy-mark" => 0, 
            "write-back" => 0
        );

        
        if($this->doUpload()) {
        // if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/uvm-1.1d/docs/html/files/base/uvm_printer-svh.html")) {
        // if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/docs/htmldocs/mgc_html_help/overview04.html")) {
            $this->listFile();
            
            $this->tidyup($tidyOpts);
            $this->cleanUVMFile();
            $this->substituteTemplates();
            $this->eliminateCruft();
            // $this->showRaw();
            // $this->showContent();
            $this->saveArticle();
        } else {
            $this->showForm();
        }
        return true;
    }
    
    private function makeTitle ( $namespace = NS_MAIN ) {
        $desiredTitleObj = Title::makeTitleSafe( $namespace, $this->mFilename );
        if (!is_null($desiredTitleObj)) {
            return $desiredTitleObj;
        } else {
            die($this->mFilename . " is an invalid filename");
        }
    }
    
    private function saveArticle () {
        $out = $this->getOutput();
        $user = $this->getUser();
        $token = $user->getEditToken();
        $title = $this->makeTitle( NS_MAIN );
        $api = new ApiMain(
            new DerivativeRequest(
                $this->getRequest(), // Fallback upon $wgRequest if you can't access context
                array(
                    'action'     => 'edit',
                    'title'      => $title,
                    'text'       => $this->mContent,  // can only use one of 'text' or 'appendtext'
                    'summary'    => $this->mComment,
                    'notminor'   => true,
                    'token'      => $token
                ),
                true // was posted?
            ),
            true // enable write?
        );
 
        // this test is not actually valid
        if ($api->execute()) {
            $out->addWikiText('<div class="success">' . $title . ' saved to [[' . $title . ']]</div>');
        } else {
            $out->addWikiText('<div class="error">' . $title . ' already exists at [[' . $title . ']]</div>');
        }
        $logEntry = new ManualLogEntry( 'html2wiki', 'import' ); // Log action 'import' in the Special:Log for 'html2wiki'
        $logEntry->setPerformer( $user ); // User object, the user who performed this action
        $logEntry->setTarget( $title ); // The page that this log entry affects, a Title object
        $logEntry->setComment( $this->mComment );
        $logid = $logEntry->insert();
        // optionally publish the log item to recent changes
        // $logEntry->publish( $logid );
    }
    
    static function saveCat( $filename, $category ) {
        global $wgContLang, $wgUser;
		$mediaString = strtolower( $wgContLang->getNsText( NS_FILE ) );
		$title = $mediaString . ':' . $filename;
		$text = "\n[[" . $category . "]]";
		$wgEnableWriteAPI = true;    
		$params = new FauxRequest(array (
			'action' => 'edit',
			'section'=> 'new',
			'title' =>  $title,
			'text' => $text,
			'token' => $wgUser->getEditToken(),//$token."%2B%5C",
		), true, $_SESSION );
		$enableWrite = true; // This is set to false by default, in the ApiMain constructor
		$api = new ApiMain( $params, $enableWrite );
		$api->execute();
		$data = &$api->getResultData();
		return $mediaString;
	}
    
    /**
     * We don't have to worry about access restrictions here, because the whole
     * SpecialPage is restricted to users with "import" privs.
     * @var string $message is an optional error message that gets displayed 
     * on form re-display
     * 
     */
	private function showForm( $message=null ) {
		$action = $this->getPageTitle()->getLocalURL( array( 'action' => 'submit' ) );
		$user = $this->getUser();
		$out = $this->getOutput();
        $out->addModules( array('ext.Html2Wiki') ); // add our javascript and css
        $out->addWikiMsg('html2wiki-intro');       
        // display an error message if any
        if ($message) {
            $out->addHTML('<div class="error">' . $message . "</div>\n");
        }
        
		if ( $user->isAllowed( 'importupload' ) ) {
			$out->addHTML(
				Xml::fieldset( $this->msg( 'html2wiki-fieldset-legend' )->text() ) . // ->plain() or ->escaped()
					Xml::openElement(
						'form',
						array(
							'enctype' => 'multipart/form-data',
							'method' => 'post',
							'action' => $action,
							'id' => 'html2wiki-form'           // new id
						)
					) .
					$this->msg( 'html2wiki-text' )->parseAsBlock() . 
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'upload' ) .
					Xml::openElement( 'table', array( 'id' => 'html2wiki-table' ) ) . // new id
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'html2wiki-filename' )->text(), 'userfile' ) . 
					"</td>
					<td class='mw-input'>" .
					Html::input( 'userfile', '', 'file', array( 'id' => 'userfile' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-comment' )->text(), 'mw-import-comment' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'log-comment', 50,
						( $this->sourceName == 'upload' ? $this->logcomment : '' ), // value
						array( 'id' => 'mw-import-comment', 'type' => 'text' ) ) . ' ' . // attribs
					"</td>
				</tr>
				<tr>
					<td></td>
					<td class='mw-submit'>" .
					Xml::submitButton( $this->msg( 'uploadbtn' )->text() ) .
					"</td>
				</tr>" .
					Xml::closeElement( 'table' ) .
					Html::hidden( 'editToken', $user->getEditToken() ) .
					Xml::closeElement( 'form' ) .
					Xml::closeElement( 'fieldset' )
			);
		} else {
			$out->addWikiMsg( 'html2wiki-not-allowed' );
		}
		
	}

    /**
     * The Tidy class can easily use an array.
     * To REUSE that same array in a command line environment, we reformat it
     * @param array $tidyOpts
     * @return string
     */
    private function makeConfigStringForTidy($tidyOpts) {
        $tidyOptsString = '';
        foreach ( $tidyOpts as $k => $v ) {
            $tidyOptsString .= " --$k $v";
        }
        return $tidyOptsString;
    }

    /**
     * Tidyup will populate both mContent and mContentTidy 
     * and store errors in mTidyErrors
     * 
     * @param string | array $tidyConfig
     * if passed as an array like so
            $tidyConfig = array(
                'indent'        => false,
                'output-xhtml'  => true,
                'wrap'          => 80
            );
     * then tidy will use the supplied configuration
     * Or, if passed as a string, tidy will use the configuration file
     * Left alone, it will load the config file supplied with Html2Wiki
     * 
     * On success, populate $this->mIsTidy = true
     * @return boolean
     * 
     * In testing tidy from the command line, it would NOT use a configuration file
     * If you have an existing tidy.conf you want to use, you can convert a config
     * awk '{ printf " --"$0 }' /vagrant/mediawiki/extensions/Html2Wiki/tidy.conf
     * 
     * @todo capture Tidy errors for logging or display?
     * see http://stackoverflow.com/questions/6472102/redirecting-i-o-in-php
     * @todo figure out if we need to use "force" option in Tidy?
     */
    public function tidyup ($tidyConfig = NULL) {
        $this->mTidyConfig = realpath( __DIR__  . "/../tidy.conf");
        if (is_null($tidyConfig)) {
            $tidyConfig = $this->mTidyConfig;
            $shellConfig = " -config $tidyConfig";
        } elseif (is_array($tidyConfig)) {
            $shellConfig = $this->makeConfigStringForTidy($tidyConfig);
        } elseif (is_string($tidyConfig)) {
            $shellConfig = " -config $tidyConfig";
            if ( !is_readable($tidyConfig) ) {
                // echo "Tidy's config not found, or not readable\n";
            }
        }
        if (class_exists('Tidy')) {
            $encoding = 'utf8';
            $tidy = new Tidy;
            $tidy->parseString($this->mContentRaw, $tidyConfig, $encoding);
            $tidy->cleanRepair();
            // @todo need to do something else with this error list?
            if(!empty($tidy->errorBuffer)) {
                $this->mTidyErrors = $tidy->errorBuffer;
            }
            // just focus on the body of the document
            $this->mContentTidy = $this->mContent = (string) $tidy->body();
        } else {
            $tidy = "/usr/bin/tidy";
            // only by passing the options as a long string worked.
            $cmd= "$tidy -quiet -indent -ashtml $shellConfig {$_FILES['userfile']['tmp_name']}";
            $escaped_command = escapeshellcmd($cmd);
            if ( !is_readable($_FILES['userfile']['tmp_name']) ) {
                echo "Tidy's target not found, or not readable\n";
            }
            //echo "executing $escaped_command";
            $this->mContentTidy = $this->mContent = shell_exec($escaped_command);
        }
        $this->isTidy = true;
        return true;
    }
    
    public function cleanUVMFile () {
       // delete content tags
        $reHead = "#<head>.*?</head>#is";
        $reBody = "#<body\b[^>]*>(.*?)</body>#is";
        $reScript = "#<script\b[^>]*>(.*?)</script>#is"; // caseless dot-all
        $reNoscript = "#<noscript\b[^>]*>(.*?)</noscript>#is"; // caseless dot-all
        $reComment = "#<!--(.*?)-->#s"; 
        $reEmptyAnchor = "#<a\b[^>]*></a>#is"; // empty anchor tags
        $reCollapsePre = '#</pre>\s*?<pre class="pCode">#s'; // sibling pre tags
        $reBlankLine = "#^\s?$\n#m";
        // simulate Tidy by ditching the <head> and getting only the <body>
        // but the source HTML could still be in a hurtful mess
        if (!$this->isTidy) {
            $this->mContent = preg_replace($reHead, '', $this->mContent);
            $this->mContent = preg_filter($reBody, "$1", $this->mContent);
        }
        $this->mContent = preg_replace($reScript, '', $this->mContent);
        $this->mContent = preg_replace($reNoscript, '', $this->mContent);
        $this->mContent = preg_replace($reEmptyAnchor, '', $this->mContent);
        $this->mContent = preg_replace($reCollapsePre, '', $this->mContent);
        
        $this->mContent = preg_replace($reComment, '', $this->mContent);
        // keep this last, because there will be a lot of blank lines generated
        $this->mContent = preg_replace($reBlankLine, '', $this->mContent);

    }
    
    
    /**
     * A method to find certain HTML fragments that you wish to replace with 
     * Templates that you've designed in your wiki.
     * 
     * For example, you're importing a large set of pages and every page contains 
     * a common footer.
     * 
     * You can define those strings in this method, and swap in the wiki
     * templates.  Corresponding wiki templates must be made by hand. 
     * 
     * For example, we'll replace 
     *     <div class="BlankFooter" id="BlankFooter">&nbsp;</div>
     *     <div class="Footer" id="Footer">&nbsp;</div>
     * with 
     *     {{BlankFooter}}
     *     {{Footer}}
     * 
     * Note: this function uses string replacement, not regular expressions
     * Also, we use a case insensitive match because <div and <Div are the same
     * in HTML.  Although the strings /should/ be consistent throughout your source
     * we'll assume that you might still be hand editing HTML which introduces
     * such inconsistencies.
     */
    public function substituteTemplates () {
        $myReplacements = array(
            '<div class="BlankFooter" id="BlankFooter">&nbsp;</div>' 
            => '{{BlankFooter}}',
            '<div class="Footer" id="Footer">&nbsp;</div>'
            => '{{Footer}}'
        );
        $this->mContent = str_ireplace(array_keys($myReplacements), array_values($myReplacements), $this->mContent);
    }
    
    /**
     * Similar to substituteTemplates() but this function is for removing 
     * leftover presentational or functional HTML that is not suitable content
     * for the wiki-fied version.
     * 
     * E.g.  <div id="BodyPopup" class="BodyPopup"></div>
     *       <div class="HideBody" id="HideBody">&nbsp;</div>
     * should be removed from the source HTML
     */
    public function eliminateCruft () {
        $myNeedles = array(
            '<div id="BodyPopup" class="BodyPopup"></div>', 
            '<div class="HideBody" id="HideBody">&nbsp;</div>'
        );
        $this->mContent = str_ireplace($myNeedles, '', $this->mContent);

    }
}

