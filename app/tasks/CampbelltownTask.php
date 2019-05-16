<?php

use Aiden\Models\Das;

class CampbelltownTask extends _BaseTask {

    public $council_name = "Campbelltown";
    public $council_website_url = "http://www.campbelltown.nsw.gov.au";
    public $council_params = [];
    public $council_default_param = "";

    /**
     * Tells the server we're looking for development applications
     */
    public function enquiryStep1($url, $calledByScrapeMethod = true) {

        $logMsg = "Telling the server we're looking for development applications, requesting cookies...";
        $this->logger->info($logMsg);

        $formData = $this->getAspFormDataByUrl($url);
        $formData['ctl00$MainBodyContent$mContinueButton'] = "Next";
        $formData['ctl00$mHeight'] = 653;
        $formData['ctl00$mWidth'] = 786;

        // Page gives different output not allowing us to scrape the addresses, change option to 2 when called by scrapeMeta
        if ($calledByScrapeMethod === true) {
            $formData['mDataGrid:Column0:Property'] = 'ctl00$MainBodyContent$mDataList$ctl03$mDataGrid$ctl04$ctl00';
        }
        else {
            $formData['mDataGrid:Column0:Property'] = 'ctl00$MainBodyContent$mDataList$ctl03$mDataGrid$ctl02$ctl00';
        }

        $formData['__LASTFOCUS'] = null;
        $formData = http_build_query($formData);

        $requestHeaders = [
            "Host: ebiz.campbelltown.nsw.gov.au",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: en-GB,en;q=0.5",
            "Accept-Encoding: none",
            "Referer: https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx?ModuleCode=LAP",
            "Content-Type: application/x-www-form-urlencoded",
            "Connection: keep-alive",
            "DNT: 1",
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

            $logMsg = "cURL error in enquiryStep1 function: " . $errmsg . " (" . $errno . ")";
            $this->logger->info($logMsg);
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
    public function enquiryStep2($formData) {

        $logMsg = "Telling server the period we're looking for, requesting more cookies...";
        $this->logger->info($logMsg);

        $url = "https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquirySearch.aspx";

        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mEnquiryListsDropDownList'] = 23;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mSearchButton'] = "Search";
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mStreetNameTextBox'] = null;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mStreetNumberTextBox'] = null;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mStreetTypeDropDown'] = null;
        $formData['ctl00$MainBodyContent$mGeneralEnquirySearchControl$mTabControl$ctl04$mSuburbTextBox'] = null;
        $formData['hiddenInputToUpdateATBuffer_CommonToolkitScripts'] = 1;
        $formData['ctl00$mHeight'] = 653;
        $formData['ctl00$mWidth'] = 786;

        $formData = http_build_query($formData);

        $requestHeaders = [
            "Host: ebiz.campbelltown.nsw.gov.au",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language: en-GB,en;q=0.5",
            "Accept-Encoding: none",
            "Referer: https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx?ModuleCode=LAP",
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

            $logMsg = "cURL error in enquiryStep2 function: " . $errmsg . " (" . $errno . ")";
            $this->logger->info($logMsg);
            return false;
        }

        return $output;

    }

    public function scrapeAction($params = []) {

        $url = "https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx" . "?ModuleCode=LAP";
        $this->logger->info($url);

        // Start on page 2, as the output of the first page (url above) will contain page 1 entries
        // (This variable is only used after we have checked the first page)
        $currentPage = 2;

        // Tell server we're looking for development applications, and retrieve required ASP variables
        $formData = $this->enquiryStep1($url);
        if ($formData === false) {
            $logMsg = "Error getting form data";
            $this->logger->info($logMsg);
            return false;
        }

        // Tell server we're looking for development applications, and retrieve output from initial page
        $output = $this->enquiryStep2($formData);
        if ($output === false) {
            $logMsg = "Error getting output";
            $this->logger->info($logMsg);
            return false;
        }

        // Now loop until no more result
        $tries = 0;
        $lastResultsHash = "";
        while (true) {

            $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
            if (!$html) {

                $logMsg = "Could not parse HTML";
                $this->logger->info($logMsg);
                return false;
            }

            $resultElements = $html->find("tr[class=ContentPanel], tr[class=AlternateContentPanel]");
            if (count($resultElements) === 0) {
                break;
            }

            // Requesting a higher page number than available pages will just yield the last page
            // Check if the results are different on each page, they should be, if not break the loop.
            $currentCouncilReferences = [];
            foreach ($resultElements as $resultElement) {

                $anchorElement = $resultElement->children(0)->children(0);
                $currentCouncilReferences[] = $this->cleanString($anchorElement->innertext());
            }

            $currentResultsHash = md5(json_encode($currentCouncilReferences));
            if ($lastResultsHash !== $currentResultsHash) {
                $lastResultsHash = $currentResultsHash;
            }
            else {
                break;
            }

            foreach ($resultElements as $resultElement) {

                // Council URL + Reference
                $anchorElement = $resultElement->children(0)->children(0)->children(0);
                $daCouncilUrl = "https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/" . $this->cleanString($anchorElement->href);
                $daCouncilReference = $this->cleanString($anchorElement->innertext());

                if (preg_match('/EnquiryDetailView\.aspx\?Id=([0-9]+)$/', $daCouncilUrl, $matches) !== 0) {
                    $daCouncilReferenceAlt = $matches[1];
                }

                $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
                $da->setCouncilId($this->getCouncil()->getId());
                $da->setCouncilReference($daCouncilReference);
                $da->setCouncilUrl($daCouncilUrl);

                if (isset($daCouncilReferenceAlt)) {
                    $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
                }

                $this->saveDa($da);
            }

            $url = "https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquirySummaryView.aspx"
                    . "?PageNumber=" . $currentPage;

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
        $logMsg = "Done";
        $this->logger->info($logMsg);

    }

    // Bit of a hack to get the correct cookies when scraping meta
    public function acceptTerms() {

        $url = "https://ebiz.campbelltown.nsw.gov.au/ePathway/Production/Web/GeneralEnquiry/EnquiryLists.aspx" . "?ModuleCode=LAP";

        $formData = $this->enquiryStep1($url, false);
        if ($formData === false) {
            $logMsg = "Error getting form data";
            $this->logger->info($logMsg);
            return false;
        }

        return ($this->enquiryStep2($formData) !== false);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;

        $legendElements = $html->find("legend");
        foreach ($legendElements as $legendElement) {

            $legendText = $this->cleanString($legendElement->innertext());
            if (strpos(strtolower($legendText), "address") === false) {
                continue;
            }

            $fieldsetElement = $legendElement->parent();
            if ($fieldsetElement === null) {
                continue;
            }

            // This HTML is so so badly formatted, but luckily they use our required classes when we want them to.
            $fieldsetHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($fieldsetElement->innertext());
            if ($fieldsetHtml === false) {
                return false;
            }

            $addressElements = $fieldsetHtml->find("div[class=ContentText],div[class=AlternateContentText]");
            foreach ($addressElements as $addressElement) {

                $address = $this->cleanString($addressElement->innertext());
                if ($this->saveAddress($da, $address)) {
                    $addedAddresses++;
                }
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;

        $url = sprintf("https://documents.campbelltown.nsw.gov.au/dwroot/datawrks/views/application/%s/links", $da->getCouncilReferenceAlt());

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

            $logMsg = sprintf("cURL error: [%s] (%s)\r\n", $errmsg, $errno);
            $this->logger->info($logMsg);
            return false;
        }

        $html = \Sunra\PhpSimple\HtmlDomParser::str_get_html($output);
        if (!$html) {

            $logMsg = "Could not parse HTML";
            $this->logger->info($logMsg);
            return false;
        }

        $anchorElements = $html->find("a");
        foreach ($anchorElements as $anchorElement) {

            if (!isset($anchorElement->href) || strpos($anchorElement->id, "classabbrev") === false) {
                continue;
            }

            $regexPattern = '/\/viewDocument\?docid=([0-9]+)/';
            if (preg_match($regexPattern, $anchorElement->href, $matches) !== 0) {

                $documentName = $this->cleanString($anchorElement->innertext());
                $documentUrl = "https://documents.campbelltown.nsw.gov.au" . $this->cleanString($anchorElement->href);
                $documentDate = null;

                // Find document date, documents page is very badly structured
                $dateElement = $html->find("a[id=docdate." . $matches[1] . "]", 0);
                if ($dateElement !== null) {
                    $documentDate = \DateTime::createFromFormat("d/m/Y", $this->cleanString($dateElement->innertext()));
                }

                if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                    $addedDocuments++;
                }
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {

        $addedApplicants = 0;
        $legendElement = $html->find("legend");

        foreach ($legendElement as $legendElement) {

            $legendText = $this->cleanString($legendElement->innertext());
            if (strpos(strtolower($legendText), "applicant") === false) {
                continue;
            }

            $fieldsetElement = $legendElement->parent();
            if ($fieldsetElement === null) {
                continue;
            }

            $fieldsetHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($fieldsetElement->innertext());
            if ($fieldsetHtml === false) {
                continue;
            }

            $applicantElements = $fieldsetHtml->find("[class=ContentText],[class=AlternateContentText]");
            foreach ($applicantElements as $applicantElement) {

                $role = "Applicant";
                $name = $this->cleanString($applicantElement->innertext());

                if (strlen($name) > 0 && $this->saveParty($da, $role, $name)) {
                    $addedApplicants++;
                }
            }
        }

        return ($addedApplicants > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=AlternateContentHeading]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "description") === false) {
                continue;
            }

            $spanElement = $headerElement->next_sibling();
            if ($spanElement === null) {
                return false;
            }

            $valueElement = $spanElement->children(0);
            if ($valueElement === null) {
                return false;
            }

            $value = $this->cleanString($valueElement->innertext());
            return (strlen($value) > 0 && $this->saveDescription($da, $value));
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=AlternateContentHeading]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "lodged") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                return false;
            }

            $value = $this->cleanString($valueElement->innertext());
            $date = \DateTime::createFromFormat("j/m/Y", $value);
            return $this->saveLodgeDate($da, $date);
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=AlternateContentHeading]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "officer") === false) {
                continue;
            }

            // Their HTML is badly formatted, and for some reason there's an orphan <td>
            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                return false;
            }

            if ($valueElement->tag !== "div") {
                $valueElement = $valueElement->children(0);

                if ($valueElement === null) {
                    continue;
                }
            }

            $role = "Officer";
            $name = $this->cleanString($valueElement->innertext());

            return (strlen($name) > 0 && $this->saveParty($da, $role, $name));
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {
        return false;

    }

}
