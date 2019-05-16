<?php

use Aiden\Models\Das;

class HornsbyshireTask extends _BaseTask {

    public $council_name = "Hornsby Shire";
    public $council_website_url = "http://www.hornsby.nsw.gov.au/";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    /**
     * Scrapes Hornsby Shire development applications
     */
    public function scrapeAction($params = []) {

        $url = "http://hscenquiry.hornsby.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&k=LodgementDate"
                . "&t=DA"
                . "&o=xml";

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

        $daElements = $html->find("Application");
        foreach ($daElements as $daElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($daElement->innertext());
            if ($daHtml === false) {
                $this->logger->error("Could not parse development application XML");
                continue;
            }

            $daCouncilReferenceElement = $daHtml->find("ReferenceNumber", 0);
            if ($daCouncilReferenceElement === null) {
                $this->logger->error("Could not find council reference element in development application XML");
                continue;
            }

            $daCouncilReference = $this->cleanString($daCouncilReferenceElement->innertext());

            $daCouncilReferenceAltElement = $daHtml->find("ApplicationId", 0);
            if ($daCouncilReferenceElement === null) {
                $this->logger->error("Could not find alternative council reference element in development application XML, "
                        . "this is a critical field that is required for generating the council URL");
                continue;
            }

            $daCouncilReferenceAlt = $this->cleanString($daCouncilReferenceAltElement->innertext());
            $daCouncilUrl = "http://hscenquiry.hornsby.nsw.gov.au/Pages/XC.Track/SearchApplication.aspx?id=" . $daCouncilReferenceAlt;

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilUrl($daCouncilUrl);
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilReferenceAlt($daCouncilReferenceAlt);
            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $propertyElements = $html->find("a[title=Click to display property details]");

        foreach ($propertyElements as $propertyElement) {

            $address = $this->cleanString($propertyElement->innertext());
            if (strlen($address) > 0 && $this->saveAddress($da, $address) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Development Application - (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {
                return $this->saveDescription($da, $this->cleanString($matches[1]));
            }
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $documentContainerElement = $html->find("[id=b_ctl00_ctMain_info_docs]", 0);

        if ($documentContainerElement === null) {
            return false;
        }

        $docsHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($documentContainerElement->innertext());
        if ($docsHtml === false) {
            return false;
        }

        $anchorElements = $docsHtml->find("a");
        foreach ($anchorElements as $anchorElement) {

            $regexPattern = '/Common\/Output\/Document\.aspx\?id=/';
            if (preg_match($regexPattern, $anchorElement->href) === 0) {
                continue;
            }

            $documentUrl = str_replace("../../", "/", $anchorElement->href);
            $documentUrl = "http://hscenquiry.hornsby.nsw.gov.au" . $documentUrl;

            $parentElement = $anchorElement->parent();
            if ($parentElement === null) {
                continue;
            }

            $documentNameElement = $parentElement->next_sibling();
            if ($documentNameElement === null) {
                continue;
            }

            $documentName = $this->cleanString($documentNameElement->innertext());

            $documentDateElement = $documentNameElement->next_sibling();
            if ($documentDateElement !== null) {

                $documentDateString = $this->cleanString($documentDateElement->innertext());
                $documentDate = \DateTime::createFromFormat("d/m/Y", $documentDateString);
            }

            if ($this->saveDocument($da, $documentName, $documentUrl, $documentDate)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Estimated Cost of Work: \$ (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {
                return $this->saveEstimatedCost($da, $matches[1]);
            }
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Lodged: ([0-9]{2}\/[0-9]{2}\/[0-9]{4})/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $date = \DateTime::createFromFormat("d/m/Y", $matches[1]);
                return $this->saveLodgeDate($da, $date);
            }
        }

        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {

        $detailsElement = $html->find("[id=b_ctl00_ctMain_info_app]", 0);
        if ($detailsElement === null) {
            return false;
        }

        $detailsString = $this->cleanString($detailsElement->innertext());
        $detailsArray = explode("<br />", $detailsString);

        foreach ($detailsArray as $detail) {

            $regexPattern = '/Officer: (.+)/';
            if (preg_match($regexPattern, $detail, $matches) === 1) {

                $role = "Officer";
                $name = $this->cleanString($matches[1]);
                return $this->saveParty($da, $role, $name);
            }
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;
        $peopleElement = $html->find("[id=b_ctl00_ctMain_info_party]", 0);

        if ($peopleElement === null) {
            return false;
        }

        $peopleString = $this->cleanString($peopleElement->innertext());
        $peopleArray = explode("<br />", $peopleString);

        foreach ($peopleArray as $person) {

            $personArray = explode("-", $person);
            if (count($personArray) === 1) {

                $role = "Applicant";
                $name = $this->cleanString($personArray[0]);

                if (strlen($name) > 0 && $this->saveParty($da, $role, $name)) {
                    $addedPeople++;
                }
            }
            else if (count($personArray) === 2) {

                $role = $this->cleanString($personArray[0]);
                $name = $this->cleanString($personArray[1]);

                if (strlen($name) > 0 && $this->saveParty($da, $role, $name)) {
                    $addedPeople++;
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
