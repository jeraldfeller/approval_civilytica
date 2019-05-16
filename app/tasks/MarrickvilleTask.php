<?php

use Aiden\Models\Das;

class MarrickvilleTask extends _BaseTask {

    public $council_name = "Marrickville";
    public $council_website_url = "http://www.marrickville.nsw.gov.au/";
    public $council_params = [];
    public $council_default_param = "";
    public $inner_west_id = 17;

    /**
     * Scrapes Blacktown development applications
     */
    public function scrapeAction($params = []) {

        $url = "https://eproperty.marrickville.nsw.gov.au/eServices/P1/PublicNotices/AllPublicNotices.aspx"
                . "?r=MC.P1.WEBGUEST"
                . "&f=%24P1.ESB.PUBNOTAL.ENQ";

        $this->logger->info($url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", ["errmsg" => $errmsg, "errno" => $errno]);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        // Get grid containing the DAs
        $gridElement = $html->find("div[id=ctl00_Content_cusApplicationResultsGrid_pnlCustomisationGrid]", 0);

        // Loop through its children, each <div> (has no id or class) is a development application element
        foreach ($gridElement->children() as $daElement) {

            // The DAs are separated by <br>'s, skip these.
            if ($daElement->tag !== "div") {
                continue;
            }

            $daTableElement = $daElement->children(0);
            foreach ($daTableElement->children() as $rowElement) {

                $header = $this->cleanString($rowElement->children(0)->innertext());
                $valueElement = $rowElement->children(1);

                // Council URL + Reference
                if ($header == "Application ID") {

                    $anchorElement = $valueElement->children(0);
                    if ($anchorElement === null) {
                        continue;
                    }

                    $daCouncilUrl = "https://eproperty.marrickville.nsw.gov.au/eServices/P1/PublicNotices/" . $this->cleanString($anchorElement->href);
                    $daCouncilReference = $this->cleanString($anchorElement->innertext());
                }

                /** Lodge Date
                 * This council doesn't provide the lodgement date anywhere, so assuming the scraper runs at least
                 * once a day it should be able to accurately set the lodgement date.
                 */
                $daLodgeDate = new \DateTime();
            }

            $da = Das::exists($this->inner_west_id, $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->inner_west_id);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilUrl($daCouncilUrl);
            $da->setLodgeDate(new \DateTime()); // Council does not have lodge dates.
            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done...");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "address") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());

            // Check if LOT is available
            $headerParent = $headerElement->parent();
            if ($headerParent !== null) {

                $lotRowElement = $headerParent->next_sibling();
                if ($lotRowElement !== null) {

                    $lotHeaderElement = $lotRowElement->children(0);
                    if ($lotHeaderElement !== null) {

                        $lotHeaderText = $this->cleanString($lotHeaderElement->innertext());
                        if (strpos(strtolower($lotHeaderText), "legal description") !== false) {

                            $lotValueParent = $lotHeaderElement->next_sibling();
                            if ($lotValueParent !== null) {

                                $lotValueElement = $lotValueParent->children(0);
                                if ($lotValueElement !== null) {

                                    $lotValue = $this->cleanString($lotValueElement);
                                    if (strlen($lotValue) > 0) {

                                        $value = $lotValue . ", " . $value;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($this->saveAddress($da, $value)) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/applicant name/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return (strlen($value) > 0 && $this->saveParty($da, "Applicant", $value));
        }

        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {


        $this->logger->info('-------------DESCRIPTION START-----------------');
        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/application description/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $this->logger->info($value);
            $this->logger->info('-------------DESCRIPTION END-----------------');
            return (strlen($value) > 0 && $this->saveDescription($da, $value));
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $url = "https://gotrim.marrickville.nsw.gov.au/WebGrid/default.aspx"
                . "?s=PlanningDocuments"
                . "&container=" . $da->getCouncilReference();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->config->directories->cookiesDir . 'cookies.txt');
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config->useragent);

        $output = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->logger->error("cURL error: {errmsg} ({errno})", [
                "errmsg" => $errmsg,
                "errno" => $errno
            ]);
            return false;
        }

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$docsHtml) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $anchorElements = $docsHtml->find("a[title=Download]");
        foreach ($anchorElements as $anchorElement) {

            $documentUrl = $this->cleanString($anchorElement->href);

            // Document Date (used)
            $documentUrlParentElement = $anchorElement->parent();
            if ($documentUrlParentElement === null) {
                continue;
            }

            $documentDateElement = $documentUrlParentElement->prev_sibling();
            if ($documentDateElement === null) {
                continue;
            }

            $documentDateString = $documentDateElement->innertext();
            $documentDateStringParts = explode(" ", $documentDateString);
            $documentDate = \DateTime::createFromFormat("j/m/Y", $documentDateStringParts[0]);

            // Document File Size (unused)
            $documentSizeElement = $documentDateElement->prev_sibling();
            if ($documentSizeElement === null) {
                continue;
            }

            // Document Type (unused)
            $documentTypeElement = $documentSizeElement->prev_sibling();
            if ($documentTypeElement === null) {
                continue;
            }

            // Document Name (used)
            $documentNameElement = $documentTypeElement->prev_sibling();
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString($documentNameElement->innertext());

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $this->logger->info('-------------Lode Date Start-----------------');
        $headerElements = $html->find("[class=headerColumn]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/closing date/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            $daLodgeDate = \DateTime::createFromFormat("d/m/Y", $value);
            $daLodgeDateMod = $daLodgeDate->modify('-28 days');
            $this->logger->info('-------------Lode Date END-----------------');
            return $this->saveLodgeDate($da,$daLodgeDateMod);
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

}
