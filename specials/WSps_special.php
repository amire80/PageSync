<?php
/**
 * Overview for the WSps extension
 *
 * @file
 * @ingroup Extensions
 */

/**
 * Class WSpsSpecial
 */
class WSpsSpecial extends SpecialPage {

	public $url, $version, $logo, $assets;

	/**
	 * WSpsSpecial constructor.
	 */
	public function __construct() {
		parent::__construct( 'WSps' );
	}


	/**
	 * Special page group
	 *
	 * @return string
	 */
	function getGroupName() : string {
		return 'Wikibase';
	}

	/**
	 * @param string $text
	 * @param string $type
	 *
	 * @return string
	 */
	function makeAlert( string $text, string $type = "danger" ) : string {
		$ret = '<div class="uk-alert-' . $type . ' uk-margin-large-top" uk-alert>';
		$ret .= '<a class="uk-alert-close" uk-close></a>';
		$ret .= '<p>' . $text . '</p></div>';

		return $ret;
	}

	/**
	 * @param string $name
	 * @param bool $checkIfEmpty
	 *
	 * @return false|mixed
	 */
	public function getPost( string $name, bool $checkIfEmpty = true ) {
		if ( $checkIfEmpty ) {
			if ( isset( $_POST[$name] ) && ! empty( $_POST[$name] ) ) {
				return $_POST[$name];
			} else {
				return false;
			}
		}
		if ( isset( $_POST[$name] ) ) {
			return $_POST[$name];
		} else {
			return false;
		}
	}

	/**
	 * @param string $name
	 * @param bool $checkIfEmpty
	 *
	 * @return false|mixed
	 */
	public function getGet( string $name, bool $checkIfEmpty = true ) {
		if ( $checkIfEmpty ) {
			if ( isset( $_GET[$name] ) && ! empty( $_GET[$name] ) ) {
				return $_GET[$name];
			} else {
				return false;
			}
		}
		if ( isset( $_GET[$name] ) ) {
			return $_GET[$name];
		} else {
			return false;
		}
	}

	/**
	 * @param WSpsRender $render
	 * @param int $activeTab
	 *
	 * @return string
	 */
	private function setResourcesAndMenu( WSpsRender $render, int $activeTab ) {
		$ret = $render->loadResources();
		$ret .= $render->renderMenu(
			$this->url,
			$this->logo,
			$this->version,
			$activeTab
		);

		return $ret;
	}

	/**
	 * @param string|false $query
	 * @param bool $returnUnFiltered
	 *
	 * @return array|false|mixed
	 */
	public function doAsk( $query = false, bool $returnUnFiltered = false ) {
		if ( $query === false ) {
			$query = '[[Class::Managed item]] [[Status of managed item::Live]] |link=none |sep=<br> |limit=9999';
		}
		$api = new ApiMain(
			new DerivativeRequest(
				$this->getRequest(),
				// Fallback upon $wgRequest if you can't access context
				array(
					'action' => 'ask',
					'query'  => $query
				),
				true // treat this as a POST
			),
			false // not write.
		);
		$api->execute();
		$data = $api->getResult()->getResultData();
		if ( ! isset( $data['query']['results'] ) ) {
			return false;
		}

		$data = $data['query']['results'];

		if ( ! $returnUnFiltered ) {
			$listOfPages = array();
			foreach ( $data as $page ) {
				$listOfPages[] = $page['fulltext'];
			}
			sort( $listOfPages );

			return $listOfPages;
		} else {
			return $data;
		}
	}

	/**
	 * Show the page to the user
	 *
	 * @param string|null $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		global $IP, $wgScript, $wgUser;
		$out            = $this->getOutput();
		$usr            = $wgUser->getName();
		$groups         = $wgUser->getGroups();
		$showAnyMessage = false;
		if ( ! in_array(
			'sysop',
			$groups
		) ) {
			$out->addHTML( '<p>Nothing to see here, only interesting stuff for Admins</p>' );

			return true;
		}
		WSpsHooks::setConfig();

		if ( WSpsHooks::$config === false ) {
			$out->addHTML( '<p>' . wfMessage( 'wsps-api-error-no-config-body' )->text() . '</p>' );

			return true;
		}

		include( $IP . '/extensions/WSPageSync/assets/classes/WSpsRender.class.php' );

		$render = new WSpsRender();

		$this->url     = rtrim(
			$wgScript,
			'index.php'
		);
		$this->version = \ExtensionRegistry::getInstance()->getAllThings()["WSPageSync"]["version"];
		$this->logo    = '/extensions/WSPageSync/assets/images/wspagesync.png';
		$this->assets  = '/extensions/WSPageSync/assets/images/';
		$style         = $render->getStyle( $this->assets );

		$wspsAction = $this->getGet( 'action' );

		// First handle serving backup file for download, before we output anything
		if ( false !== $wspsAction && strtolower( $wspsAction ) === 'backup' ) {
			$pAction = $this->getPost( 'wsps-action' );
			if ( $pAction === 'download-backup' ) {
				$backupHandler = new WSpsBackupHandler();
				$backupHandler->setBackFile( $this->getPost( 'ws-backup-file' ) );
				$backupHandler->downloadBackup();
			}
		}

		$this->setHeaders();
		$out->setPageTitle( '' );

		switch ( strtolower( $wspsAction ) ) {
			case "convert":
				$out->addHTML(
					$this->setResourcesAndMenu(
						$render,
						0
					)
				);
				$convertHandler = new WSpsConvertHandler();
				if ( WSpsHooks::checkFileConsistency() === false ) {
					$pAction = $this->getPost( 'wsps-action' );

					// Do the actual conversion
					if ( $pAction === 'wsps-convert-real' ) {
						$out->addHTML( $convertHandler->convertForReal( $render ) );
						$out->addHTML( $style );

						return true;
					}

					// Preview files affected
					$out->addHTML( $convertHandler->preview( $render ) );
					$out->addHTML( $style );

					return true;
				}
				break;
			case "backup":
				$out->addHTML(
					$this->setResourcesAndMenu(
						$render,
						2
					)
				);
				$psBackup         = new WSpsHooksBackup();
				$backActionResult = false;

				// check if we have zip extension
				if( !extension_loaded( 'zip' ) ) {
					$out->addHTML( $this->makeAlert( wfMessage( 'wsps-special_backup_we_need_zip_extension' )->text() ) );
					$out->addHTML( $style );
					return true;
				}

				//Handle any backup actions
				$pAction = $this->getPost( 'wsps-action' );
				switch ( $pAction ) {
					case "wsps-backup":
						$psBackup->createZipFileBackup();
						break;
					case "delete-backup":
						$resultDeleteBackup = false;
						$backupFile         = $this->getPost( 'ws-backup-file' );
						if ( $backupFile !== false ) {
							$resultDeleteBackup = $psBackup->deleteBackupFile( $backupFile );
						}
						if ( $resultDeleteBackup === true ) {
							$backActionResult = wfMessage(
								'wsps-special_backup_delete_file_success',
								$backupFile
							)->text();
						} else {
							$backActionResult = wfMessage(
								'wsps-special_backup_delete_file_error',
								$backupFile
							)->text();
						}
						break;
					case "restore-backup":
						$backActionResult = false;
						$backupFile       = $this->getPost( 'ws-backup-file' );
						if ( $backupFile !== false ) {
							$resRestore = $psBackup->restoreBackupFile( $backupFile );
							if ( $resRestore === true ) {
								$backActionResult = wfMessage(
									'wsps-special_backup_restore_file_success',
									$backupFile
								)->text();
							} else {
								$backActionResult = wfMessage(
									'wsps-special_backup_restore_file_failure',
									$backupFile
								)->text();
							}
						}
						break;
				}

				// Show list of backups
				$data = $psBackup->getBackupList();
				$nr   = count( $data );
				$html = wfMessage(
					'wsps-special_backup_count',
					$nr
				)->text();
				if ( $nr >= 1 ) {
					$html .= $render->renderBackups(
						$data
					);
				}
				$btn_backup = '<form method="post" action="' . $wgScript . '/Special:WSps?action=backup">';
				$btn_backup .= '<input type="hidden" name="wsps-action" value="wsps-backup">';
				$btn_backup .= '<input type="submit" class="uk-button uk-button-primary uk-margin-small-bottom uk-text-small" value="';
				$btn_backup .= wfMessage( 'wsps-error_file_consistency_btn_backup' )->text();
				$btn_backup .= '"></form>';

				$html .= $btn_backup;
				if ( $backActionResult !== false ) {
					$out->addHTML( $backActionResult );
				}
				$out->addHTML( '<h3>' . $this->msg( 'wsps-content_backups' ) . '</h3>' );
				$out->addHTML( $style );
				$out->addHTML( $html );

				return true;
				break;
			case "exportcustom":
				$out->addHTML(
					$this->setResourcesAndMenu(
						$render,
						1
					)
				);
				// First check if we have SMW
				if( ! ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
					$out->addHTML( $this->makeAlert( wfMessage( 'wsps-special_custom_query_we_need_smw' )->text() ) );
					$out->addHTML( $style );
					return true;
				}

				$pAction = $this->getPost( 'wsps-action' );
				$error   = '';

				switch ( $pAction ) {
					case "wsps-import-query" :
						$query = $this->getPost( 'wsps-query' );

						if ( $query === false ) {
							$error = $this->makeAlert( wfMessage( 'wsps-special_managed_query_not_found' )->text() );
						} else {
							$query       = base64_decode( $query );
							$listOfPages = $this->doAsk( $query );
							$nr          = count( $listOfPages );
							$count       = 1;
							foreach ( $listOfPages as $page ) {
								$pageId = WSpsHooks::getPageIdFromTitle( $page );
								if ( $pageId === false ) {
								} else {
									$result = WSpsHooks::addFileForExport(
										$pageId,
										$usr
									);
									if ( $result['status'] === false ) {
									}
								}
								$count++;
							}
							$content = '<h2>' . wfMessage( 'wsps-special_status_card_done' )->text() . '</h2>';
							$content .= '<p>Added ' . ( $count - 1 ) . '/' . $nr . ' pages.</p>';
							$out->addHTML( $content );

							return true;
						}
						break;
					case "doQuery" :
						$query = $this->getPost( 'wsps-query' );

						if ( $query === false ) {
							$error = $this->makeAlert( wfMessage( 'wsps-special_custom_query_not_found' )->text() );
						} else {
							$result = $this->doAsk( $query );

							$nr = count( $result );

							$form       = $render->renderDoQueryForm( $query );
							$html       = $form;
							$bodyResult = $render->renderDoQueryBody( $result );
							$html       .= $bodyResult['html'];

							$header = wfMessage( 'wsps-special_custom_query_result' )->text();
							$header .= '<p>' . wfMessage( 'wsps-special_custom_query' )->text(
								) . '<span class="uk-text-warning">' . htmlspecialchars( $query ) . '</span></p>';
							$header .= wfMessage(
								'wsps-special_custom_query_result_text1',
								$nr
							)->text();
							$header .= wfMessage(
								'wsps-special_custom_query_result_text2',
								$bodyResult['active']
							)->text();
							$html   = $header . $html;
							$html   .= $form;
							$out->addHTML( $style );
							$out->addHTML( $html );

							return true;
						}
						break;
					case false :
						break;
				}

				if ( $error !== '' ) {
					echo $error;
				}

				$out->addHTML( $render->renderCustomQuery() );

				return true;
				break;
		}

		// Render Main page

		$out->addHTML(
			$this->setResourcesAndMenu(
				$render,
				0
			)
		);

		// Do we have any results here then show them
		if ( false !== $showAnyMessage ) {
			$out->addHTML( $showAnyMessage );
		}

		// Render file consistency check failed
		if ( WSpsHooks::checkFileConsistency() === false ) {
			$numberOfBadFiles = WSpsHooks::checkFileConsistency( true );
			$btn_backup       = '<form method="post" action="' . $wgScript . '/Special:WSps?action=backup">';
			$btn_backup       .= '<input type="hidden" name="wsps-action" value="wsps-backup">';
			$btn_backup       .= '<input type="submit" class="uk-button uk-button-primary uk-margin-small-bottom uk-text-small" value="';
			$btn_backup       .= wfMessage( 'wsps-error_file_consistency_btn_backup' )->text();
			$btn_backup       .= '"></form>';
			$btn_convert      = '<form method="post" action="' . $wgScript . '/Special:WSps?action=convert">';
			$btn_convert      .= '<input type="hidden" name="wsps-action" value="wsps-convert">';
			$btn_convert      .= '<input type="submit" class="uk-button uk-button-primary uk-margin-small-bottom uk-text-small" value="';
			$btn_convert      .= wfMessage( 'wsps-error_file_consistency_btn_convert' )->text();
			$btn_convert      .= '"></form>';
			$out->addHTML(
				$render->renderCard(
					$this->msg( 'wsps-error_file_consistency_0' ),
					$this->msg( 'wsps-error_file_consistency_1' ),
					'<p>' . $this->msg( 'wsps-error_file_consistency_2' ) . '<br>' . $this->msg(
						'wsps-error_file_consistency_count',
						$numberOfBadFiles
					) . '<br>' . $this->msg( 'wsps-error_file_consistency_3' ) . '<br>' . $this->msg(
						'wsps-error_file_consistency_4'
					),
					'<table><tr><td>' . $btn_backup . '</td><td>' . $btn_convert . '</td></tr></table>'
				)
			);
			$out->addHTML( $style );

			return true;
		}

		// Render default main page

		$data = WSpsHooks::getAllPageInfo();
		$nr   = count( $data );
		$html = wfMessage(
			'wsps-special_count',
			$nr
		)->text();
		if ( $nr >= 1 ) {
			$html .= $render->renderIndexPage(
				$data,
				$wgScript
			);
		}
		$out->addHTML( '<h3>' . $this->msg( 'wsps-content' ) . '</h3>' );
		$out->addHTML( $style );
		$out->addHTML( $html );

		return true;
	}
}
