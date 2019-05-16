<?php

use Aiden\Models\Das;

class MosmanTask extends _BaseTask {

    public $council_name = "Mosman";
    public $council_website_url = "https://www.mosman.nsw.gov.au/";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        if (!isset($params[0])) {
            return false;
        }

        $url = "https://portal.mosman.nsw.gov.au/pages/xc.track/SearchApplication.aspx"
                . "?d=" . $params[0]
                . "&t=8,5"
                . "&k=LodgementDate";

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

            // Council Reference + URL
            $anchorElement = $resultElement->children(0);
            if ($anchorElement === null) {
                continue;
            }

            $daCouncilUrl = "https://portal.mosman.nsw.gov.au/pages/xc.track/" . $this->cleanString($anchorElement->href);
            $daCouncilReference = $this->cleanString($anchorElement->innertext());

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

        $headerElements = $html->find("[class=ndetailleft]");
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
            $documentUrl = "https://portal.mosman.nsw.gov.au" . $documentUrl;

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

        $headerElements = $html->find("[class=ndetailleft]");
        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (preg_match("/estimated cost/i", $headerText) === 0) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $value = $this->cleanString($valueElement->innertext());
            return $this->saveEstimatedCost($da, $value);
        }

        return false;

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        $headerElements = $html->find("[class=ndetailleft]");
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

    protected function extractOfficers($html, $da, $params = null): bool {

        $addedOfficers = 0;
        $headerElements = $html->find("[class=ndetailleft]");

        foreach ($headerElements as $headerElement) {

            $headerText = $this->cleanString($headerElement->innertext());
            if (strpos(strtolower($headerText), "officer") === false) {
                continue;
            }

            $valueElement = $headerElement->next_sibling();
            if ($valueElement === null) {
                continue;
            }

            $officersArray = explode("<br />", $valueElement->innertext());
            foreach ($officersArray as $officer) {

                $officer = $this->cleanString($officer);
                if (strlen($officer) > 0 && $this->saveParty($da, "Officer", $officer)) {
                    $addedOfficers++;
                }
            }
        }

        return ($addedOfficers > 0);

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

}
