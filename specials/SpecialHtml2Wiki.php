<?php
/**
 * Import/Upload SpecialPage for Html2Wiki extension
 *
 * @file
 * @ingroup Extensions
 * @package Html2Wiki
 */

class SpecialHtml2Wiki extends SpecialPage {
    /**
     * Right now we'll just construct with the name of the page
     */
    public function __construct() {
        parent::__construct('Html2Wiki'); 
        // we might want to add rights here, or else do it in a method called in exectute
        //parent::__construct('Import Html', array('upload', 'reupload');
    }
    /**
     * override the parent to set where the special page appears on Special:SpecialPages
     * other is the default, so you do not need to override if that's what you want
     * specify 'media' to use the specialpages-group-media system interface 
     * message, which translates to 'Media reports and uploads' in English;
     * 
     * @return string
     */
    function getGroupName() {
        // 'Media reports and uploads'
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
    }

}
