<?php

use Aiden\Models\Das;

class ThehillsTask extends _BaseTask {

    public $council_name = "The Hills";
    public $council_website_url = "http://www.thehills.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * Tells the server we're looking for development applications
     */
    public function enquiryStep1($url) {

        $this->logger->info("Telling server we're looking for development applications...");

        $formData = $this->getAspFormDataByUrl($url);
        $formData['ctl00$MainBodyContent$mContinueButton'] = "Next";
        $formData['ctl00$mHeight'] = 653;
        $formData['ctl00$mWidth'] = 786;
        $formData['mDataGrid:Column0:Property'] = 'ctl00$MainBodyContent$mDataList$ctl03$mDataGrid$ctl02$ctl00';
        $formData['__LASTFOCUS'] = null;
        $formData = http_build_query($formData);

        $requestHeaders = [
            "Host: epathway.thehills.nsw.gov.au",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: en-GB,en;q=0.5",
            "Accept-Encoding: none",
            "Referer: https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx",
            "Content-Type: application/x-www-form-urlencoded",
            "Connection: keep-alive",
            "dnt: 1",
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
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

            $message = "cURL error in enquiryStep1 function: " . $errmsg . " (" . $errno . ")";
            $this->logger->error($message);
            return false;
        }

        $newFormData = $this->getAspFormDataByString($output);
        return $newFormData;

    }

    /**
     * Tells the server the period we want development applications from
     * @param type $formData
     * @return boolean
     */
    public function enquiryStep2($formData, $startDate, $endDate) {

        $this->logger->info("Telling server the period...");

        $url = "https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquirySearch.aspx";

        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mEnquiryListsDropDownList'] = 7;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mSearchButton'] = "Search";
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$DateSearchRadioGroup'] = "mLast30RadioButton";
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mFromDatePicker$dateTextBox'] = $startDate;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mToDatePicker$dateTextBox'] = $endDate;
        $formData['ctl00$mHeight'] = 653;
        $formData['ctl00$mWidth'] = 786;

        $formData = http_build_query($formData);

        $requestHeaders = [
            "Host: epathway.thehills.nsw.gov.au",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: en-GB,en;q=0.5",
            "Accept-Encoding: none",
            "Referer: https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx",
            "Content-Type: application/x-www-form-urlencoded"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$this->config->dev);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$this->config->dev);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $formData);
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

            $message = "cURL error in enquiryStep2 function: " . $errmsg . " (" . $errno . ")";
            $this->logger->error($message);
            return false;
        }

        return $output;

    }

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        switch ($params[0]) {
            case "thisweek":
                $dateStart = new DateTime("next Monday -1 week");
                $dateEnd = new DateTime("next Monday");
                break;
            case "lastweek":
                $dateStart = new DateTime("next Monday -2 weeks");
                $dateEnd = new DateTime("next Monday -1 week");
                break;
            case "thismonth":
                $dateStart = new DateTime("-30 days");
                $dateEnd = new DateTime();
                break;
            case "lastmonth":
                $dateStart = new DateTime("first day of last month");
                $dateEnd = new DateTime("last day of last month");
                break;
            default:
                return false;
        }

        $startDate = $dateStart->format("d/m/Y");
        $endDate = $dateEnd->format("d/m/Y");

        $url = "https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx";
        $currentPage = 2; // Start on page 2

        $requiredFormData = $this->enquiryStep1($url);
        if ($requiredFormData === false) {
            return false;
        }

        $output = $this->enquiryStep2($requiredFormData, $startDate, $endDate);
        if ($output === false) {
            return false;
        }

        // Now loop until no more result
        $tries = 0;
        $lastResultsHash = "";
        while (true) {

            $this->logger->info("Checking " . $url . "...");

            // Try to parse HTML
            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {
                $message = "Could not parse HTML";
                $this->logger->error($message);
                return false;
            }

            $file = fopen("test.txt","w");
            fwrite($file,$output);
            fclose($file);

            $resultElements = $html->find("tr[class=ContentPanel], tr[class=AlternateContentPanel]");
            if (count($resultElements) === 0) {
                $this->logger->info("No results.");
                break;
            }

            // Requesting a higher page number than available pages will just yield the last page
            // Check if the results are different on each page, they should be.
            $currentCouncilReferences = [];
            foreach ($resultElements as $resultElement) {
                $anchorElement = $resultElement->children(0)->children(0);
                $currentCouncilReferences[] = $this->cleanString($anchorElement->innertext());
            }

            $currentResultsHash = md5(json_encode($currentCouncilReferences));
            $this->logger->info("Page " . $currentPage . ": " . $currentResultsHash . "");
            if ($lastResultsHash !== $currentResultsHash) {
                $lastResultsHash = $currentResultsHash;
            }
            else {
                $this->logger->info("Detected same page as before. Bye.");
                break;
            }

            foreach ($resultElements as $resultElement) {

                $anchorContainerElement = $resultElement->children(0);
                if ($anchorContainerElement === null) {
                    continue;
                }

                $anchorElement = $anchorContainerElement->children(0);
                if ($anchorElement === null) {
                    continue;
                }

                $daCouncilUrl = "https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/";
                $daCouncilUrl .= $this->cleanString($anchorElement->href);

                $urlParts = explode("=", $daCouncilUrl);

                $daCouncilReference = $this->cleanString($anchorElement->innertext());
                $daCouncilReferenceAlt = $urlParts[count($urlParts) - 1];

                $descriptionContainerElement = $resultElement->children(2);
                if ($descriptionContainerElement === null) {
                    continue;
                }

                $descriptionElement = $descriptionContainerElement->children(0);
                if ($descriptionElement === null) {
                    continue;
                }

                $daDescription = $this->cleanString($descriptionElement->innertext());

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilUrl($daCouncilUrl);
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
                $da->setDescription($daDescription);
                $this->saveDa($da);
            }

            // Increment page and set output
            $url = "https://epathway.thehills.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquirySummaryView.aspx?PageNumber=" . $currentPage;

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
                $tries++;
                continue;
            }

            $currentPage++;
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $headingElements = $html->find("[class=AlternateContentHeading]");
        foreach ($headingElements as $headingElement) {

            $headingText = $this->cleanString($headingElement->innertext());
            if (preg_match("/application location/i", $headingText) === 0) {
                continue;
            }

            $valueElement = $headingElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            // The Hills Council magic???
            if ($valueElement->tag === "td") {
                $valueElement = $valueElement->children(0);
                if ($valueElement === null) {
                    continue;
                }
            }

            $value = $this->cleanString($valueElement->innertext());

            if ($this->saveAddress($da, $value) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDescription($html, $da, $params = null): bool {
        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $regexPattern = '/\/ApplicationTracking\/Document\/View\?key=/';
        $addedDocuments = 0;
        $url = "https://apps.thehills.nsw.gov.au/ApplicationTracking/Application/Documents/" . $da->getCouncilReferenceAlt();

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

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$docsHtml) {
            $this->logger->error("Could not parse HTML");
            return false;
        }

        $anchorElements = $docsHtml->find("a");
        foreach ($anchorElements as $anchorElement) {

            $documentUrl = "https://apps.thehills.nsw.gov.au";
            $documentUrl .= $this->cleanString($anchorElement->href);

            $urlParentElement = $anchorElement->parent();
            if ($urlParentElement === null) {
                continue;
            }

            $documentDateElement = $urlParentElement->prev_sibling();
            if ($documentDateElement === null) {
                continue;
            }

            $documentDate = $this->cleanString($documentDateElement->innertext());
            $documentDate = \DateTime::createFromFormat("j/m/Y", $documentDate);

            $documentNameElement = $documentDateElement->prev_sibling();
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

        $headingElements = $html->find("[class=AlternateContentHeading]");
        foreach ($headingElements as $headingElement) {

            $headingText = $this->cleanString($headingElement->innertext());
            if (preg_match("/lodgement date/i", $headingText) === 0) {
                continue;
            }

            $valueElement = $headingElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            // The Hills Council magic???
            if ($valueElement->tag === "td") {
                $valueElement = $valueElement->children(0);
                if ($valueElement === null) {
                    continue;
                }
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("j/m/Y", $value);
            return $this->saveLodgeDate($da, $date);
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
