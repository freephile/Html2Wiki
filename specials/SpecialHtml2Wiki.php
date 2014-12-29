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
    /**
     * Right now we'll just construct with the name of the page
     */
    public function __construct($name='Html2Wiki') {
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

        $out = $this->getOutput();

        $out->setPageTitle($this->msg('html2wiki-title'));

        $out->addWikiMsg('html2wiki-intro');
		
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
			$this->doImport();
		}
		$this->showForm();

    }

	/**
	 * Do the actual import
	 */
	private function doImport() {
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
	
	private function showForm() {
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

	
}
