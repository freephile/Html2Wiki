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
    
	/** @var string The HTML we want to turn into wiki text **/
    public $mContent;

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
	 * Do the actual import
     * 
	 */
    private function doImport() {
        
        global $wgMaxUploadSize;
        
        // We'll need to send the form input to parse.js
        // and the response/output will be wikitext.
        // We'll either be able to insert that programmatically
        // or use OutputPage->addWikiText() to make it appear in the page output for initial testing
        
        // check whether we should use Tidy. 
        $config = $this->getConfig();
        if ( ( $config->get( 'UseTidy' ) && $options->getTidy() ) || $config->get( 'AlwaysUseTidy' ) ) {
            // $tmp = MWTidy::tidy( $tmp );
        }
        $_MaxUploadSize = $config->get('MaxUploadSize');
        
        /* For some tags, we might have to "trick" Tidy into not stripping them, like in MWTidy.php
        // Modify inline Microdata <link> and <meta> elements so they say <html-link> and <html-meta> so
		// we can trick Tidy into not stripping them out by including them in tidy's new-empty-tags config
		$wrappedtext = preg_replace( '!<(link|meta)([^>]*?)(/{0,1}>)!', '<html-$1$2$3', $wrappedtext );

		// Wrap the whole thing in a doctype and body for Tidy.
		$wrappedtext = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"' .
			' "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html>' .
			'<head><title>test</title></head><body>' . $wrappedtext . '</body></html>';
        */
        
        
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
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_NO_FILE:
                    throw new RuntimeException('No file sent.');
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new RuntimeException('Exceeded filesize limit.');
                default:
                    throw new RuntimeException('Unknown errors.');
            }
            // You should also check filesize here. 
            if ($_FILES['userfile']['size'] > $_MaxUploadSize['*']) {
                throw new RuntimeException('Exceeded filesize limit defined as ' . $_MaxUploadSize['*'] . '.');
            }
            /*
            // DO NOT TRUST $_FILES['userfile']['mime'] VALUE !!
            // Check MIME Type by yourself.
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (false === $ext = array_search(
                $finfo->file($_FILES['userfile']['tmp_name']),
                array(
                    'text/html',
                    'js'
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
            echo $e->getMessage();
        }
        $out = $this->getOutput();
        // $out->addHTML('<pre>' . print_r($_FILES) . '</pre>');
        $out->addHTML('<div>' . $_FILES['userfile']['name'] . ' was uploaded successfully.</div>');
        $out->addHTML('<div>Original File Contents:</div>');
        $string = file_get_contents($_FILES['userfile']['tmp_name']);
        $this->mContent = $string;
        $this->tidyup();
        // $_escapedContents = nl2br(htmlentities($string, ENT_QUOTES | ENT_IGNORE, "UTF-8"));
        // $out->addHTML(<div>$_escapedContents</div>);
        $out->addWikiText('<source lang="html4strict">' . $this->mContent . '</source>');

        return true;
    }
    
    
    /**
     * The code below is actually from Special:Import as an example
     * @return type
     * @throws PermissionsError
     */
	private function doSpecialImport() {
		$isUpload = false;
		$request = $this->getRequest();
		$this->namespace = $request->getIntOrNull( 'namespace' );
		$this->sourceName = $request->getVal( "source" );

		$this->logcomment = $request->getText( 'log-comment' );
		$this->pageLinkDepth = $this->getConfig()->get( 'ExportMaxLinkDepth' ) == 0
			? 0
			: $request->getIntOrNull( 'pagelink-depth' );
		$this->rootpage = $request->getText( 'rootpage' );

		$user = $this->getUser();
		if ( !$user->matchEditToken( $request->getVal( 'editToken' ) ) ) {
			$source = Status::newFatal( 'import-token-mismatch' );
		} elseif ( $this->sourceName == 'upload' ) {
			$isUpload = true;
			if ( $user->isAllowed( 'importupload' ) ) {
				$source = ImportStreamSource::newFromUpload( "xmlimport" );
			} else {
				throw new PermissionsError( 'importupload' );
			}
		} elseif ( $this->sourceName == "interwiki" ) {
			if ( !$user->isAllowed( 'import' ) ) {
				throw new PermissionsError( 'import' );
			}
			$this->interwiki = $this->fullInterwikiPrefix = $request->getVal( 'interwiki' );
			// does this interwiki have subprojects?
			$importSources = $this->getConfig()->get( 'ImportSources' );
			$hasSubprojects = array_key_exists( $this->interwiki, $importSources );
			if ( !$hasSubprojects && !in_array( $this->interwiki, $importSources ) ) {
				$source = Status::newFatal( "import-invalid-interwiki" );
			} else {
				if ( $hasSubprojects ) {
					$this->subproject = $request->getVal( 'subproject' );
					$this->fullInterwikiPrefix .= ':' . $request->getVal( 'subproject' );
				}
				if ( $hasSubprojects && !in_array( $this->subproject, $importSources[$this->interwiki] ) ) {
					$source = Status::newFatal( "import-invalid-interwiki" );
				} else {
					$this->history = $request->getCheck( 'interwikiHistory' );
					$this->frompage = $request->getText( "frompage" );
					$this->includeTemplates = $request->getCheck( 'interwikiTemplates' );
					$source = ImportStreamSource::newFromInterwiki(
						$this->fullInterwikiPrefix,
						$this->frompage,
						$this->history,
						$this->includeTemplates,
						$this->pageLinkDepth );
				}
			}
		} else {
			$source = Status::newFatal( "importunknownsource" );
		}

		$out = $this->getOutput();
		if ( !$source->isGood() ) {
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>",
				array( 'importfailed', $source->getWikiText() )
			);
		} else {
			$importer = new WikiImporter( $source->value, $this->getConfig() );
			if ( !is_null( $this->namespace ) ) {
				$importer->setTargetNamespace( $this->namespace );
			}
			if ( !is_null( $this->rootpage ) ) {
				$statusRootPage = $importer->setTargetRootPage( $this->rootpage );
				if ( !$statusRootPage->isGood() ) {
					$out->wrapWikiMsg(
						"<p class=\"error\">\n$1\n</p>",
						array(
							'import-options-wrong',
							$statusRootPage->getWikiText(),
							count( $statusRootPage->getErrorsArray() )
						)
					);

					return;
				}
			}

			$out->addWikiMsg( "importstart" );

			$reporter = new ImportReporter(
				$importer,
				$isUpload,
				$this->fullInterwikiPrefix,
				$this->logcomment
			);
			$reporter->setContext( $this->getContext() );
			$exception = false;

			$reporter->open();
			try {
				$importer->doImport();
			} catch ( MWException $e ) {
				$exception = $e;
			}
			$result = $reporter->close();

			if ( $exception ) {
				# No source or XML parse error
				$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>",
					array( 'importfailed', $exception->getMessage() )
				);
			} elseif ( !$result->isGood() ) {
				# Zero revisions
				$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>",
					array( 'importfailed', $result->getWikiText() )
				);
			} else {
				# Success!
				$out->addWikiMsg( 'importsuccess' );
			}
			$out->addHTML( '<hr />' );
		}
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
        $out->addWikiMsg('html2wiki-intro');
		$importSources = $this->getConfig()->get( 'ImportSources' );
		
        // display an error message if any
        if ($message) {
            $out->addHTML('<div class="error">' . $message . "</div>\n");
        }
        
		if ( $user->isAllowed( 'importupload' ) ) {
			$out->addHTML(
				Xml::fieldset( $this->msg( 'import-html-fieldset-legend' )->text() ) . // ->plain() or ->escaped()
					Xml::openElement(
						'form',
						array(
							'enctype' => 'multipart/form-data',
							'method' => 'post',
							'action' => $action,
							'id' => 'mw-import-html-form'           // new id
						)
					) .
					$this->msg( 'import-html-text' )->parseAsBlock() . 
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'upload' ) .
					Xml::openElement( 'table', array( 'id' => 'mw-import-table-html' ) ) . // new id
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-html-filename' )->text(), 'userfile' ) . 
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
			$out->addWikiMsg( 'import-html-not-allowed' );
		}
		
	}
	/**
	 * The code below is from the SpecialImport.php file
	 */
	private function showSpecialImportForm() {
		$action = $this->getPageTitle()->getLocalURL( array( 'action' => 'submit' ) );
		$user = $this->getUser();
		$out = $this->getOutput();
		$importSources = $this->getConfig()->get( 'ImportSources' );

		if ( $user->isAllowed( 'importupload' ) ) {
			$out->addHTML(
				Xml::fieldset( $this->msg( 'import-upload' )->text() ) .
					Xml::openElement(
						'form',
						array(
							'enctype' => 'multipart/form-data',
							'method' => 'post',
							'action' => $action,
							'id' => 'mw-import-upload-form'
						)
					) .
					$this->msg( 'importtext' )->parseAsBlock() .
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'upload' ) .
					Xml::openElement( 'table', array( 'id' => 'mw-import-table-upload' ) ) .
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-upload-filename' )->text(), 'xmlimport' ) .
					"</td>
					<td class='mw-input'>" .
					Html::input( 'xmlimport', '', 'file', array( 'id' => 'xmlimport' ) ) . ' ' .
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
					<td class='mw-label'>" .
					Xml::label(
						$this->msg( 'import-interwiki-rootpage' )->text(),
						'mw-interwiki-rootpage-upload'
					) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'rootpage', 50, $this->rootpage,
						array( 'id' => 'mw-interwiki-rootpage-upload', 'type' => 'text' ) ) . ' ' .
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
			if ( empty( $importSources ) ) {
				$out->addWikiMsg( 'importnosources' );
			}
		}

		if ( $user->isAllowed( 'import' ) && !empty( $importSources ) ) {
			# Show input field for import depth only if $wgExportMaxLinkDepth > 0
			$importDepth = '';
			if ( $this->getConfig()->get( 'ExportMaxLinkDepth' ) > 0 ) {
				$importDepth = "<tr>
							<td class='mw-label'>" .
					$this->msg( 'export-pagelinks' )->parse() .
					"</td>
							<td class='mw-input'>" .
					Xml::input( 'pagelink-depth', 3, 0 ) .
					"</td>
				</tr>";
			}

			$out->addHTML(
				Xml::fieldset( $this->msg( 'importinterwiki' )->text() ) .
					Xml::openElement(
						'form',
						array(
							'method' => 'post',
							'action' => $action,
							'id' => 'mw-import-interwiki-form'
						)
					) .
					$this->msg( 'import-interwiki-text' )->parseAsBlock() .
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'interwiki' ) .
					Html::hidden( 'editToken', $user->getEditToken() ) .
					Xml::openElement( 'table', array( 'id' => 'mw-import-table-interwiki' ) ) .
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-interwiki-sourcewiki' )->text(), 'interwiki' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::openElement(
						'select',
						array( 'name' => 'interwiki', 'id' => 'interwiki' )
					)
			);

			$needSubprojectField = false;
			foreach ( $importSources as $key => $value ) {
				if ( is_int( $key ) ) {
					$key = $value;
				} elseif ( $value !== $key ) {
					$needSubprojectField = true;
				}

				$attribs = array(
					'value' => $key,
				);
				if ( is_array( $value ) ) {
					$attribs['data-subprojects'] = implode( ' ', $value );
				}
				if ( $this->interwiki === $key ) {
					$attribs['selected'] = 'selected';
				}
				$out->addHTML( Html::element( 'option', $attribs, $key ) );
			}

			$out->addHTML(
				Xml::closeElement( 'select' )
			);

			if ( $needSubprojectField ) {
				$out->addHTML(
					Xml::openElement(
						'select',
						array( 'name' => 'subproject', 'id' => 'subproject' )
					)
				);

				$subprojectsToAdd = array();
				foreach ( $importSources as $key => $value ) {
					if ( is_array( $value ) ) {
						$subprojectsToAdd = array_merge( $subprojectsToAdd, $value );
					}
				}
				$subprojectsToAdd = array_unique( $subprojectsToAdd );
				sort( $subprojectsToAdd );
				foreach ( $subprojectsToAdd as $subproject ) {
					$out->addHTML( Xml::option( $subproject, $subproject, $this->subproject === $subproject ) );
				}

				$out->addHTML(
					Xml::closeElement( 'select' )
				);
			}

			$out->addHTML(
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-interwiki-sourcepage' )->text(), 'frompage' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'frompage', 50, $this->frompage, array( 'id' => 'frompage' ) ) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::checkLabel(
						$this->msg( 'import-interwiki-history' )->text(),
						'interwikiHistory',
						'interwikiHistory',
						$this->history
					) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
					Xml::checkLabel(
						$this->msg( 'import-interwiki-templates' )->text(),
						'interwikiTemplates',
						'interwikiTemplates',
						$this->includeTemplates
					) .
					"</td>
				</tr>
				$importDepth
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-interwiki-namespace' )->text(), 'namespace' ) .
					"</td>
					<td class='mw-input'>" .
					Html::namespaceSelector(
						array(
							'selected' => $this->namespace,
							'all' => '',
						), array(
							'name' => 'namespace',
							'id' => 'namespace',
							'class' => 'namespaceselector',
						)
					) .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-comment' )->text(), 'mw-interwiki-comment' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'log-comment', 50,
						( $this->sourceName == 'interwiki' ? $this->logcomment : '' ),
						array( 'id' => 'mw-interwiki-comment', 'type' => 'text' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label(
						$this->msg( 'import-interwiki-rootpage' )->text(),
						'mw-interwiki-rootpage-interwiki'
					) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'rootpage', 50, $this->rootpage,
						array( 'id' => 'mw-interwiki-rootpage-interwiki', 'type' => 'text' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-submit'>" .
					Xml::submitButton(
						$this->msg( 'import-interwiki-submit' )->text(),
						Linker::tooltipAndAccesskeyAttribs( 'import' )
					) .
					"</td>
				</tr>" .
					Xml::closeElement( 'table' ) .
					Xml::closeElement( 'form' ) .
					Xml::closeElement( 'fieldset' )
			);
		}
	}

	/**
	 * Show the upload form with error message, but do not stash the file.
	 *
	 * @param string $message HTML string
	 */
	protected function showUploadError( $message ) {
		$message = '<h2>' . $this->msg( 'uploadwarning' )->escaped() . "</h2>\n" .
			'<div class="error">' . $message . "</div>\n";
		$this->showForm( $message );
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
        }
    }
}
