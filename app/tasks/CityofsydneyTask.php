<?php

use Aiden\Models\Das;

class CityofsydneyTask extends _BaseTask {

    public $council_name = "City of Sydney";
    public $council_website_url = "https://www.cityofsydney.nsw.gov.au";
    public $council_params = ["thisweek", "lastweek", "thismonth", "lastmonth"];
    public $council_default_param = "thismonth";

    public function scrapeAction($params = []) {

        $url = "http://feeds.cityofsydney.nsw.gov.au/SydneyDAs";

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

        $itemElements = $html->find("item");
        foreach ($itemElements as $itemElement) {

            $daHtml = \Sunra\PhpSimple\HtmlDomParser::str_get_html($itemElement->innertext());
            if ($daHtml === false) {
                $this->logger->error("Could not parse development application XML");
                continue;
            }

            $daCouncilUrlElement = $daHtml->find("guid", 0);
            if ($daCouncilUrlElement === null) {
                $this->logger->error("Could not find guid element, this element is required for the council URL");
                continue;
            }

            $daCouncilUrl = $this->cleanString($daCouncilUrlElement->innertext());

            $daCouncilReferenceElement = $daHtml->find("description", 0);
            if ($daCouncilReferenceElement === null) {
                $this->logger->error("Could not find description element, this element is required for the council reference.");
                continue;
            }

            $daCouncilReferencePattern = '/<p><strong>DA Number: <\/strong>(.+?)<\/p>/';
            if (preg_match($daCouncilReferencePattern, $daCouncilReferenceElement->innertext(), $matches) === 0) {
                $this->logger->error("Could not find council reference");
                continue;
            }

            $daCouncilReference = $matches[1];

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

    protected function extractDescription($html, $da, $params = null): bool {

        // Find estimated cost label
        $descriptionLabelElement = $html->find("label[for=Class_Description]", 0);
        if ($descriptionLabelElement === null) {
            return false;
        }

        // <td> containing this <label>
        $parentCellElement = $descriptionLabelElement->parent();
        if ($parentCellElement === null) {
            return false;
        }

        // <td> next to <label>'s parent
        $valueElement = $parentCellElement->next_sibling();
        if ($valueElement === null) {
            return false;
        }

        $value = $this->cleanString($valueElement->innertext());
        return $this->saveDescription($da, $value);

    }

    protected function extractLodgeDate($html, $da, $params = null): bool {

        // Find address label
        $lodgeDateLabelElement = $html->find("label[for=Lodged_Date]", 0);
        if ($lodgeDateLabelElement === null) {
            return false;
        }

        // <td> containing this <label>
        $parentCellElement = $lodgeDateLabelElement->parent();
        if ($parentCellElement === null) {
            return false;
        }

        // <td> next to <label>'s parent
        $valueElement = $parentCellElement->next_sibling();
        if ($valueElement === null) {
            return false;
        }

        $oldLodgeDate = $da->getLodgeDate();
        $newLodgeDate = \DateTime::createFromFormat("d/m/y", $this->cleanString($valueElement->innertext()));
        return $this->saveLodgeDate($da, $newLodgeDate);

    }

    protected function extractAddresses($html, $da, $params = null): bool {

        // Find address label
        $addressesLabelElement = $html->find("label[for=Addresses]", 0);
        if ($addressesLabelElement === null) {
            return false;
        }

        // <td> containing this <label>, label mentions addresses in plural, but so far
        // have only come across single addresses.
        $parentCellElement = $addressesLabelElement->parent();
        if ($parentCellElement === null) {
            return false;
        }

        // <td> next to <label>'s parent, contains the address value.
        $valueElement = $parentCellElement->next_sibling();
        if ($valueElement === null) {
            return false;
        }

        $daAddress = $this->cleanString($valueElement->innertext());
        return $this->saveAddress($da, $daAddress);

    }

    protected function extractEstimatedCost($html, $da, $params = null): bool {

        // Find estimated cost label
        $estimatedCostLabelElement = $html->find("label[for=Estimated_Cost]", 0);
        if ($estimatedCostLabelElement === null) {
            return false;
        }

        // <td> containing this <label>
        $parentCellElement = $estimatedCostLabelElement->parent();
        if ($parentCellElement === null) {
            return false;
        }

        // <td> next to <label>'s parent
        $valueElement = $parentCellElement->next_sibling();
        if ($valueElement === null) {
            return false;
        }

        return $this->saveEstimatedCost($da, $valueElement->innertext());

    }

    protected function extractDocuments($html, $da, $params = null): bool {

        $addedDocuments = 0;
        $documentsElement = $html->find("div[id=documents_info]", 0);

        if ($documentsElement === null) {
            return false;
        }

        $ulElement = $documentsElement->children(0);
        if ($ulElement === null) {
            return false;
        }

        $documentListElements = $ulElement->children();
        foreach ($documentListElements as $documentListElement) {

            $anchorElement = $documentListElement->children(0);
            if ($anchorElement === null) {
                continue;
            }

            $documentUrl = $this->cleanString($anchorElement->href);
            $documentName = $this->cleanString($anchorElement->innertext());

            if ($this->saveDocument($da, $documentName, $documentUrl)) {
                $addedDocuments++;
            }
        }

        return ($addedDocuments > 0);

    }

    protected function extractOfficer($html, $da, $params = null): bool {

        $officerLabelElement = $html->find("label[for=Officer]", 0);
        if ($officerLabelElement === null) {
            return false;
        }

        // <b> containing the <label>
        $officerParentElement = $officerLabelElement->parent();
        if ($officerParentElement === null) {
            return false;
        }

        // Get <a> next to <b>
        $valueElement = $officerParentElement->next_sibling();
        if ($valueElement === null || $valueElement->tag !== "a") {
            return false;
        }

        $role = "Officer";
        $name = $this->cleanString($valueElement->innertext());

        return $this->saveParty($da, $role, $name);

    }

    protected function extractApplicant($html, $da, $params = null): bool {
        return false;

    }

    protected function extractPeople($html, $da, $params = null): bool {
        return false;

    }

    protected function extractApplicants($html, $da, $params = null): bool {
        return false;

    }

    protected function extractOfficers($html, $da, $params = null): bool {
        return false;

    }

}
