<?php

use Aiden\Models\Das;

class NorthernbeachesTask extends _BaseTask {

    public $council_name = "Northern Beaches";
    public $council_website_url = "https://www.northsydney.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "https://eservices.northernbeaches.nsw.gov.au/ePlanning/live/Public/XC.Track/SearchApplication.aspx?d=" . $params[0];
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

        $resultElements = $html->find("div[class=result]");
        foreach ($resultElements as $resultElement) {

            // Council URL
            $daCouncilUrlPattern = '/<a href="SearchApplication\.aspx\?id=([0-9]+)" target="_self" class="search">/';
            if (preg_match($daCouncilUrlPattern, $resultElement->innertext(), $urlMatches) === 0) {
                continue;
            }

            $daCouncilUrl = "https://eservices.northernbeaches.nsw.gov.au/ePlanning/live/Public/XC.Track/SearchApplication.aspx?id=" . $urlMatches[1];

            // Council Reference
            $daCouncilReferencePattern = '/<a href="SearchApplication\.aspx\?id=[0-9]+" target="_self" class="search">(.+?)<\/a>/';
            if (preg_match($daCouncilReferencePattern, $resultElement->innertext(), $refMatches) === 0) {
                continue;
            }

            $daCouncilReference = $this->cleanString($refMatches[1]);

            $da = Das::exists($this->getCouncil()->getId(), $daCouncilReference) ?: new Das();
            $da->setCouncilId($this->getCouncil()->getId());
            $da->setCouncilReference($daCouncilReference);
            $da->setCouncilUrl($daCouncilUrl);
            $this->saveDa($da);
        }

        $this->getCouncil()->setLastScrape(new DateTime());
        $this->getCouncil()->save();
        $this->scrapeMetaAction();
        $this->logger->info("Done.");

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        $addedAddresses = 0;
        $addressElements = $html->find("a[title=Click to display property details]");

        foreach ($addressElements as $addressElement) {

            $daAddress = $this->cleanString($addressElement->innertext());
            if ($this->saveAddress($da, $daAddress) === true) {
                $addedAddresses++;
            }
        }

        return ($addedAddresses > 0);

    }

    protected function extractDescription($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=detailleft]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "description") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString(strip_tags($valueElement->innertext()));
            return $this->saveDescription($da, $value);
        }

        return false;

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $anchorElements = $html->find("a");
        $regexPattern = '/Common\/Output\/Document\.aspx\?id=/';

        foreach ($anchorElements as $anchorElement) {

            if (isset($anchorElement->href) === false) {
                continue;
            }

            if (preg_match($regexPattern, $anchorElement->href) === 0) {
                continue;
            }

            $firstChildElement = $anchorElement->children(0);
            if ($firstChildElement !== null && $firstChildElement->tag === "img") {
                continue;
            }

            $documentName = $this->cleanString(strip_tags($anchorElement->innertext()));

            $documentUrl = $this->cleanString($anchorElement->href);
            $documentUrl = str_replace("../../", "/", $documentUrl);
            $documentUrl = "https://eservices.northernbeaches.nsw.gov.au/ePlanning/live" . $documentUrl;

            $documentDate = null;

            $parentCellElement = $anchorElement->parent();
            if ($parentCellElement !== null) {

                $dateParentElement = $anchorElement->next_sibling();
                if ($dateParentElement !== null) {

                    $dateElement = $dateParentElement->children(0);
                    if ($dateElement !== null) {

                        $documentDateString = $this->cleanString($dateElement->innertext());
                        $documentDate = \DateTime::createFromFormat("d/m/Y", $documentDateString);
                    }
                }
            }

            if ($this->saveDocument($da, $documentName, $documentUrl)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        $keyElements = $html->find("[class=detailleft]");
        foreach ($keyElements as $keyElement) {


            $keyText = $this->cleanString($keyElement->innertext());

            if (preg_match("/cost of work/i", $keyText) === 0) {
                continue;
            }

            $valueElement = $keyElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveEstimatedCost($da, $value);
        }


        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=detailleft]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "submitted") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString(strip_tags($valueElement->innertext()));
            $date = \DateTime::createFromFormat("d/m/Y", $value);
            return $this->saveLodgeDate($da, $date);
        }

        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {

        $addedPeople = 0;

        $peopleElement = $html->find("div[id=b_ctl00_ctMain_info_party]", 0);
        if ($peopleElement === null) {
            return false;
        }

        $detailElement = $peopleElement->children(0);
        if ($detailElement === null) {
            return false;
        }

        $detailRightElement = $detailElement->children(0);
        if ($detailRightElement === null) {
            return false;
        }

        $rolesAndPersonsString = $this->cleanString($detailRightElement->innertext());
        $rolesAndPersonsArray = explode("<br />", $rolesAndPersonsString);

        foreach ($rolesAndPersonsArray as $roleAndPersonString) {

            $roleAndPersonArray = explode("-", $roleAndPersonString);
            if (!isset($roleAndPersonArray[1])) {
                continue;
            }

            $role = $this->cleanString($roleAndPersonArray[0]);
            $name = $this->cleanString($roleAndPersonArray[1]);

            if (strlen($name) > 0) {

                if ($this->saveParty($da, $role, $name) === true) {
                    $addedPeople++;
                }
            }
        }

        return ($addedPeople > 0);

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
