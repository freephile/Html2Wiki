<?php
/**
 * Import/Upload SpecialPage for Html2Wiki extension
 *
 * @see https://www.mediawiki.org/wiki/Manual:Special_pages
 * @file
 * @ingroup Extensions
 * @package Html2Wiki
 */

class SpecialHtml2Wiki extends SpecialPage {
    
	/** @var string The HTML we want to turn into wiki text */
    private $mContent;
    /** @var string The (original) name of the uploaded file */
    private $mFilename;
    /** @var int The size, in bytes, of the uploaded file. */
    private $mFilesize;

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

	/**
	 * Initialize instance variables from request and create an Upload handler
     * @todo review and cull the methods that we use here
     * This method was copied from Special:Upload assuming it would be 
     * applicable to our use case.
	 */
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

    
    /**
	 * Constructor : initialise object
	 * Get data POSTed through the form and assign them to the object
	 * @param WebRequest $request Data posted.
	 * We'll use the parent's constructor to instantiate the name but not perms
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
        // not sure we need all the fandango of loadRequest();  I think we can just simply do parent::getRequest();
		$request = $this->getRequest();
		if ( $request->wasPosted() && $request->getVal( 'action' ) == 'submit' ) {
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
        $this->mFilename = $_FILES['userfile']['name'];
        $this->mContent = file_get_contents($_FILES['userfile']['tmp_name']);
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
        $this->mFilename = basename($file);
        $this->mContent = file_get_contents($file);
        $this->mFilesize = filesize($file);
        return true;
    }
    /**
     * displays $this->mContent (in a <source> block)
     * optionally passed through htmlentities() and nl2br()
     */
    private function showContent($showEscaped=false) {
        $out = $this->getOutput();
        $out->addModules( array('ext.Html2Wiki', 'ext.Html2Wiki.clickable') ); // add our javascript and css
        // $out->addHTML('<pre>' . print_r($_FILES) . '</pre>');
        //$out->addHTML('<ul class="mw-ext-Html2Wiki"><li>' . $this->mFilename . '</li><ul class="mw-ext-Html2Wiki"><li>2.one</li><li>2.two</li></ul></ul>');
        $out->addHTML('<ul class="mw-ext-Html2Wiki"><li>' . $this->mFilename . '</li></ul>');
        //$this->mContent = $this->findBody();
        if($showEscaped) {
            $escapedContent = $this->escapeContent();
            $out->addHTML('<div id="h2w-label">Escaped File Contents:</div>');
            $out->addHTML('<div id="h2w-content">' . $escapedContent . '</div>');
        } else {
            $out->addHTML('<div id="h2w-label">Original File Contents:</div>');
            // putting the original source into GeSHi makes it "safe" from the parser
            // $out->addHTML(<div>$this->mContent</div>);
            $out->addWikiText('<source id="h2w-content" lang="html4strict">' . $this->mContent . '</source>');
        }
    }
    
    private function findBody() {
        
        $out = $this->getOutput();
        $content = $this->mContent;
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
    private function doTidy() {

        
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
        
        
        // if($this->doUpload()) {
        // if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/uvm-1.1d/docs/html/files/base/uvm_printer-svh.html")) {
        if($this->doLocalFile("/vagrant/mediawiki/extensions/Html2Wiki/data/docs/htmldocs/mgc_html_help/overview04.html")) {
            $this->showContent();
            $this->saveArticle();
        } else {
            $this->showForm();
        }
        
        
        // $this->tidyup();
        

        return true;
    }
    
    private function saveArticle () {
        $user = $this->getUser();
        $token = $user->editToken();
        $api = new ApiMain(
            new DerivativeRequest(
                $this->getRequest(), // Fallback upon $wgRequest if you can't access context
                array(
                    'action'     => 'edit',
                    'title'      => 'NewPage',
                    'text'       => 'Hello World',
                    // 'appendtext' => '[[Category:UVM-1.1]]',
                    'summary'    => 'This is a summary',
                    'notminor'   => true,
                    'token'      => $token
                ),
                true // was posted?
            ),
            true // enable write?
        );
 
        $api->execute();    
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
        $out->addModules( array('ext.Html2Wiki', 'ext.Html2Wiki.clickable') ); // add our javascript and css
        $out->addWikiMsg('html2wiki-intro');
		$out->addHTML( '<div id="hello">Hello World</div>' );
        
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
						( $this->sourceName == 'upload' ? $this->logcomment : '' ),
						array( 'id' => 'mw-import-comment', 'type' => 'text' ) ) . ' ' .
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
    
    public function tidyup () {
        if (class_exists('Tidy')) {
            // cleanup output
            $config = array(
                'indent'        => false,
                'output-xhtml'  => true,
                'wrap'          => 80
            );
            $encoding = 'utf8';
            $tidy = new Tidy;
            $tidy->parseString($this->mContent, $config, $encoding);
            $tidy->cleanRepair();
            if(!empty($tidy->errorBuffer)) {
              echo "The following errors or warnings occurred:\n";
              echo $tidy->errorBuffer;
            }
            // just focus on the body of the document
            $this->mContent = (string) $tidy->body();
            // convert the object to string
            // $tidy = (string) $tidy;
            return true;
        } else {
            die('unable to load Tidy');
        }
    }
}

