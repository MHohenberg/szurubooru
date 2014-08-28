<?php
namespace Szurubooru;

final class UpgradeService
{
	private $db;

	public function __construct(\MongoDB $db)
	{
		$this->db = $db;
	}

	public function prepareForUsage()
	{
		$this->db->createCollection('posts');
	}

	public function removeAllData()
	{
		foreach ($this->db->getCollectionNames() as $collectionName)
			$this->removeCollectionData($collectionName);
	}

	private function removeCollectionData($collectionName)
	{
		$this->db->$collectionName->remove();
		$this->db->$collectionName->deleteIndexes();
	}
}
