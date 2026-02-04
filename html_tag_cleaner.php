<?php

$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
	die('<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 8px; margin: 20px;">
		<strong>❌ Помилка:</strong> Файл config.php не знайдено!<br>
		<small>Переконайтеся, що скрипт знаходиться в кореневій папці магазину OpenCart/ocStore</small>
	</div>');
}

$configContent = file_get_contents($configFile);

function getConfigValue($content, $constantName) {
	if (preg_match("/define\s*\(\s*['\"]" . preg_quote($constantName, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"\s]*\)/", $content, $matches)) {
		return $matches[1];
	}
	return null;
}

$dbHostname = getConfigValue($configContent, 'DB_HOSTNAME');
$dbUsername = getConfigValue($configContent, 'DB_USERNAME');
$dbPassword = getConfigValue($configContent, 'DB_PASSWORD');
$dbDatabase = getConfigValue($configContent, 'DB_DATABASE');
$dbPort = getConfigValue($configContent, 'DB_PORT');

if (empty($dbPort)) {
	if (strpos($dbHostname, ':') !== false) {
		list($dbHostname, $dbPort) = explode(':', $dbHostname, 2);
	} else {
		$dbPort = '3306';
	}
}

if (empty($dbHostname) || empty($dbUsername) || empty($dbDatabase)) {
	die('<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 8px; margin: 20px;">
		<strong>❌ Помилка:</strong> Не вдалося зчитати дані підключення з config.php<br>
		<small>Переконайтеся, що файл config.php містить коректні константи DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE</small>
	</div>');
}

$availableConfigs = [
	'host' => $dbHostname,
	'port' => $dbPort,
	'database' => $dbDatabase,
	'username' => $dbUsername,
	'password' => $dbPassword
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	header('Content-Type: application/json; charset=utf-8');
	
	$action = $_POST['action'] ?? '';
	if (!in_array($action, ['scan', 'clean'])) {
		echo json_encode(['success' => false, 'error' => 'Недопустима дія']);
		exit;
	}

	$host = $availableConfigs['host'] ?? 'localhost';
	$port = $availableConfigs['port'] ?? '3306';
	$dbname = $availableConfigs['database'] ?? '';
	$user = $availableConfigs['username'] ?? '';
	$pass = $availableConfigs['password'] ?? '';
	
	try {
		$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		$prefixes = detectPrefixes($pdo);
		$prefix = !empty($prefixes) ? $prefixes[0] : '';
		
		if ($action === 'scan') {
			$tags = $_POST['tags_to_remove'] ?? 'color';
			echo json_encode(scanDatabase($pdo, $dbname, $prefix, $tags));
		} elseif ($action === 'clean') {
			$selectedTables = isset($_POST['selected_tables']) ? json_decode($_POST['selected_tables'], true) : [];
			$tags = $_POST['tags_to_remove'] ?? 'color';
			echo json_encode(cleanDatabase($pdo, $dbname, $prefix, $tags, $selectedTables));
		} else {
			echo json_encode(['success' => false, 'error' => 'Невідома дія']);
		}
		
	} catch (Exception $e) {
		echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	}
	exit;
}

function containsTag($html, $tagPattern) {
	if (empty($html)) {
		return false;
	}

	$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$tagPattern = trim($tagPattern);

	if (preg_match('/^(\w+)\[([^\]]+)\]$/', $tagPattern, $matches)) {
		$tagName = $matches[1];
		$attribute = $matches[2];

		if (preg_match('/(\w+)\*="([^"]+)"/', $attribute, $attrMatches)) {
			$attrName = $attrMatches[1];
			$attrValue = $attrMatches[2];

			$pattern = '/<' . preg_quote($tagName, '/') . '\s+[^>]*' . 
					   preg_quote($attrName, '/') . '\s*=\s*"[^"]*' . 
					   preg_quote($attrValue, '/') . '[^"]*"[^>]*>/is';
			return preg_match($pattern, $decoded) === 1;
		}
		elseif (preg_match('/(\w+)="([^"]+)"/', $attribute, $attrMatches)) {
			$attrName = $attrMatches[1];
			$attrValue = $attrMatches[2];

			$pattern = '/<' . preg_quote($tagName, '/') . '\s+[^>]*' . 
					   preg_quote($attrName, '/') . '\s*=\s*"' . 
					   preg_quote($attrValue, '/') . '"[^>]*>/is';
			return preg_match($pattern, $decoded) === 1;
		}
	} else {
		$tagName = $tagPattern;
		$pattern = '/<' . preg_quote($tagName, '/') . '(\s+[^>]*)?\s*>/is';
		return preg_match($pattern, $decoded) === 1;
	}
	
	return false;
}

function getAllTables($pdo) {
	try {
		$stmt = $pdo->query("SHOW TABLES");
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	} catch (PDOException $e) {
		return [];
	}
}


function extractPrefixes($tables) {
	$prefixes = [];
	foreach ($tables as $table) {
		$pos = strpos($table, '_');
		if ($pos !== false) {
			$prefix = substr($table, 0, $pos + 1);
			if (!in_array($prefix, $prefixes)) {
				$prefixes[] = $prefix;
			}
		}
	}
	return !empty($prefixes) ? $prefixes : array('');
}

function detectPrefixes($pdo) {
	$tables = getAllTables($pdo);
	return extractPrefixes($tables);
}

function scanDatabase($pdo, $dbname, $prefix, $tags) {
	$findTablesQuery = "
		SELECT TABLE_NAME, COLUMN_NAME
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_SCHEMA = :database
		AND COLUMN_NAME LIKE '%description%'
		AND TABLE_NAME LIKE :prefix
		ORDER BY TABLE_NAME, COLUMN_NAME
	";
	
	$stmt = $pdo->prepare($findTablesQuery);
	$stmt->execute(['database' => $dbname, 'prefix' => $prefix . '%']);
	
	$tables = [];
	$totalRecords = 0;

	$tagsArray = array_map('trim', explode(',', $tags));
	
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$table = $row['TABLE_NAME'];
		$column = $row['COLUMN_NAME'];

		static $pkCache = [];
		static $allKeysCache = [];
		$cacheKey = "$dbname.$table";
		
		if (!isset($pkCache[$cacheKey])) {
			$pkQuery = $pdo->prepare("
				SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = :database
				AND TABLE_NAME = :table
				AND COLUMN_KEY = 'PRI'
				LIMIT 1
			");
			$pkQuery->execute(['database' => $dbname, 'table' => $table]);
			$pkResult = $pkQuery->fetch(PDO::FETCH_ASSOC);

			if (!$pkResult) {
				$keysQuery = $pdo->prepare("
					SELECT COLUMN_NAME
					FROM INFORMATION_SCHEMA.COLUMNS
					WHERE TABLE_SCHEMA = :database
					AND TABLE_NAME = :table
					AND COLUMN_KEY IN ('PRI', 'UNI', 'MUL')
					ORDER BY ORDINAL_POSITION
				");
				$keysQuery->execute(['database' => $dbname, 'table' => $table]);
				$allKeys = [];
				while ($keyCol = $keysQuery->fetch(PDO::FETCH_ASSOC)) {
					$allKeys[] = $keyCol['COLUMN_NAME'];
				}

				$allKeysCache[$cacheKey] = $allKeys;

				if (!empty($allKeys)) {
					$pkResult = ['COLUMN_NAME' => $allKeys[0]];
				} else {
					$firstColQuery = $pdo->prepare("
						SELECT COLUMN_NAME
						FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = :database
						AND TABLE_NAME = :table
						ORDER BY ORDINAL_POSITION
						LIMIT 1
					");
					$firstColQuery->execute(['database' => $dbname, 'table' => $table]);
					$pkResult = $firstColQuery->fetch(PDO::FETCH_ASSOC);
				}
			}
			
			$pkCache[$cacheKey] = $pkResult ? $pkResult['COLUMN_NAME'] : null;
		}
		
		$primaryKey = $pkCache[$cacheKey];

		if (!$primaryKey) {
			$foundCount = 0;
			
			try {
				$selectStmt = $pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` != ''");
				$selectStmt->execute();
				
				$compiledPatterns = [];
				foreach ($tagsArray as $tag) {
					$tag = trim($tag);
					if (preg_match('/^(\w+)\[([^\]]+)\]$/', $tag, $matches)) {
						$tagName = $matches[1];
						$attribute = $matches[2];
						if (preg_match('/(\w+)\*="([^"]+)"/', $attribute, $attrMatches)) {
							$compiledPatterns[] = '/<' . preg_quote($tagName, '/') . '\s+[^>]*' . 
												preg_quote($attrMatches[1], '/') . '\s*=\s*"[^"]*' . 
												preg_quote($attrMatches[2], '/') . '[^"]*"[^>]*>/is';
						}
					} else {
						$compiledPatterns[] = '/<' . preg_quote($tag, '/') . '(\s+[^>]*)?\s*>/is';
					}
				}
				
				while ($record = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
					$content = html_entity_decode($record[$column], ENT_QUOTES | ENT_HTML5, 'UTF-8');
					
					foreach ($compiledPatterns as $pattern) {
						if (preg_match($pattern, $content)) {
							$foundCount++;
							break;
						}
					}
				}
			} catch (Exception $e) {
				continue;
			}
			
			if ($foundCount > 0) {
				if (!isset($tables[$table])) {
					$tables[$table] = [];
				}
				$tables[$table][$column] = [
					'count' => $foundCount,
					'ids' => ['N/A']
				];
				$totalRecords += $foundCount;
			}
			
			continue;
		}

		$foundIds = [];
		
		try {
			$allPkColumns = [];
			$pkQuery = $pdo->prepare("
				SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = :database
				AND TABLE_NAME = :table
				AND COLUMN_KEY = 'PRI'
				ORDER BY ORDINAL_POSITION
			");
			$pkQuery->execute(['database' => $dbname, 'table' => $table]);
			while ($pkCol = $pkQuery->fetch(PDO::FETCH_ASSOC)) {
				$allPkColumns[] = $pkCol['COLUMN_NAME'];
			}

			if (empty($allPkColumns)) {
				if (isset($allKeysCache[$cacheKey]) && !empty($allKeysCache[$cacheKey])) {
					$allPkColumns = $allKeysCache[$cacheKey];
				} else {
					$allPkColumns = [$primaryKey];
				}
			}

			$pkColumnsStr = '`' . implode('`, `', $allPkColumns) . '`';

			$selectStmt = $pdo->prepare("
				SELECT {$pkColumnsStr}, `{$column}` 
				FROM `{$table}` 
				WHERE `{$column}` IS NOT NULL 
				AND `{$column}` != ''
			");
			$selectStmt->execute();

			$previewAdded = false;

			while ($record = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
				$content = $record[$column];
				
				$hasTag = false;
				foreach ($tagsArray as $tag) {
					if (containsTag($content, $tag)) {
						$hasTag = true;
						break;
					}
				}
				
				if ($hasTag) {
					$compositeKey = [];
					foreach ($allPkColumns as $pkCol) {
						$compositeKey[$pkCol] = $record[$pkCol];
					}

					$keyExists = false;
					foreach ($foundIds as $existingKey) {
						if ($existingKey === $compositeKey) {
							$keyExists = true;
							break;
						}
					}
					
					if (!$keyExists) {
						$foundIds[] = $compositeKey;
						
						if (!isset($tables[$table])) {
							$tables[$table] = [];
						}
						if (!isset($tables[$table][$column])) {
							$tables[$table][$column] = [
								'count' => 0,
								'ids' => []
							];
						}

						if (!$previewAdded) {
							$cleanedPreview = removeTags($content, $tags);
							$tables[$table][$column]['preview'] = [
								'before' => mb_substr($content, 0, 500),
								'after' => mb_substr($cleanedPreview, 0, 500)
							];
							$previewAdded = true;
						}
					}
				}
			}
		} catch (Exception $e) {
			continue;
		}
		
		$count = count($foundIds);
		
		if ($count > 0) {
			if (!isset($tables[$table])) {
				$tables[$table] = [];
			}
			$tables[$table][$column] = [
				'count' => $count,
				'ids' => $foundIds
			];
			$totalRecords += $count;
		}
	}
	
	return [
		'success' => true,
		'tables' => $tables,
		'tables_count' => count($tables),
		'total_records' => $totalRecords,
		'tags_searched' => $tagsArray
	];
}

function cleanDatabase($pdo, $dbname, $prefix, $tags, $selectedTables = []) {
	$startTime = microtime(true);
	
	$scanResult = scanDatabase($pdo, $dbname, $prefix, $tags);
	$tables = $scanResult['tables'];
	
	$report = [];
	$totalProcessed = 0;
	$totalUpdated = 0;

	$BATCH_SIZE = 100;
	
	foreach ($tables as $table => $columns) {
		if (!empty($selectedTables) && !in_array($table, $selectedTables)) {
			continue;
		}
		
		$report[$table] = [];
		
		foreach ($columns as $column => $count) {
			$report[$table][$column] = [
				'processed' => 0,
				'updated' => 0
			];

			$pkQuery = $pdo->prepare("
				SELECT COLUMN_NAME
				FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = :database
				AND TABLE_NAME = :table
				AND COLUMN_KEY = 'PRI'
				LIMIT 1
			");
			$pkQuery->execute(['database' => $dbname, 'table' => $table]);
			$pkResult = $pkQuery->fetch(PDO::FETCH_ASSOC);
			$primaryKey = $pkResult ? $pkResult['COLUMN_NAME'] : null;

			if (!$primaryKey) {
				$keysQuery = $pdo->prepare("
					SELECT COLUMN_NAME
					FROM INFORMATION_SCHEMA.COLUMNS
					WHERE TABLE_SCHEMA = :database
					AND TABLE_NAME = :table
					AND COLUMN_KEY IN ('PRI', 'UNI', 'MUL')
					ORDER BY ORDINAL_POSITION
					LIMIT 1
				");
				$keysQuery->execute(['database' => $dbname, 'table' => $table]);
				$keyResult = $keysQuery->fetch(PDO::FETCH_ASSOC);
				
				if ($keyResult) {
					$primaryKey = $keyResult['COLUMN_NAME'];
				} else {
					$ukQuery = $pdo->prepare("
						SELECT COLUMN_NAME
						FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = :database
						AND TABLE_NAME = :table
						ORDER BY ORDINAL_POSITION
						LIMIT 1
					");
					$ukQuery->execute(['database' => $dbname, 'table' => $table]);
					$ukResult = $ukQuery->fetch(PDO::FETCH_ASSOC);
					$primaryKey = $ukResult ? $ukResult['COLUMN_NAME'] : null;
				}
			}

			if (!$primaryKey) {
				continue;
			}
			
			try {
				$idsToProcess = isset($count['ids']) ? $count['ids'] : [];

				if (empty($idsToProcess) || $idsToProcess === ['N/A']) {
					$countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` != ''");
					$countStmt->execute();
					$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

					$offset = 0;
					
					while ($offset < $totalRecords) {
						$pdo->beginTransaction();
						
						try {
							$selectStmt = $pdo->prepare("
								SELECT `{$primaryKey}`, `{$column}` 
								FROM `{$table}` 
								WHERE `{$column}` IS NOT NULL AND `{$column}` != ''
								LIMIT :limit OFFSET :offset
							");
							$selectStmt->bindValue(':limit', $BATCH_SIZE, PDO::PARAM_INT);
							$selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
							$selectStmt->execute();
							
							while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
								$id = $row[$primaryKey];
								$original = $row[$column];
								$cleaned = removeTags($original, $tags);
								
								$report[$table][$column]['processed']++;
								$totalProcessed++;
								
								if ($original !== $cleaned) {
									$updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :cleaned WHERE `{$primaryKey}` = :id");
									$updateStmt->execute(['cleaned' => $cleaned, 'id' => $id]);
									
									$report[$table][$column]['updated']++;
									$totalUpdated++;
								}
							}
							
							$pdo->commit();
							
						} catch (Exception $e) {
							$pdo->rollBack();
							break;
						}
						
						$offset += $BATCH_SIZE;
					}
				} else {
					$totalRecords = count($idsToProcess);
					$chunks = array_chunk($idsToProcess, $BATCH_SIZE);
					
					foreach ($chunks as $chunk) {
						$pdo->beginTransaction();
						
						try {
							$firstItem = reset($chunk);
							$isCompositeKey = is_array($firstItem);
							
							if ($isCompositeKey) {
								$whereClauses = [];
								$params = [];
								
								foreach ($chunk as $compositeKey) {
									$conditions = [];
									foreach ($compositeKey as $colName => $colValue) {
										$conditions[] = "`{$colName}` = ?";
										$params[] = $colValue;
									}
									$whereClauses[] = '(' . implode(' AND ', $conditions) . ')';
								}
								
								$whereClause = implode(' OR ', $whereClauses);

								$pkColumns = array_keys($firstItem);
								$pkColumnsStr = '`' . implode('`, `', $pkColumns) . '`';
								
								$selectStmt = $pdo->prepare("
									SELECT {$pkColumnsStr}, `{$column}` 
									FROM `{$table}` 
									WHERE {$whereClause}
								");
								$selectStmt->execute($params);
								
								while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
									$original = $row[$column];
									$cleaned = removeTags($original, $tags);
									
									$report[$table][$column]['processed']++;
									$totalProcessed++;
									
									if ($original !== $cleaned) {
										$updateConditions = [];
										$updateParams = ['cleaned' => $cleaned];
										
										foreach ($pkColumns as $pkCol) {
											$updateConditions[] = "`{$pkCol}` = :{$pkCol}";
											$updateParams[$pkCol] = $row[$pkCol];
										}
										
										$updateWhere = implode(' AND ', $updateConditions);
										$updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :cleaned WHERE {$updateWhere}");
										$updateStmt->execute($updateParams);
										
										$report[$table][$column]['updated']++;
										$totalUpdated++;
									}
								}
								
							} else {
								$placeholders = implode(',', array_fill(0, count($chunk), '?'));
								$selectStmt = $pdo->prepare("
									SELECT `{$primaryKey}`, `{$column}` 
									FROM `{$table}` 
									WHERE `{$primaryKey}` IN ($placeholders)
								");
								$selectStmt->execute($chunk);
								
								while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
									$id = $row[$primaryKey];
									$original = $row[$column];
									$cleaned = removeTags($original, $tags);
									
									$report[$table][$column]['processed']++;
									$totalProcessed++;
									
									if ($original !== $cleaned) {
										$updateStmt = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = :cleaned WHERE `{$primaryKey}` = :id");
										$updateStmt->execute(['cleaned' => $cleaned, 'id' => $id]);
										
										$report[$table][$column]['updated']++;
										$totalUpdated++;
									}
								}
							}
							
							$pdo->commit();
							
						} catch (Exception $e) {
							$pdo->rollBack();
							
							if (!isset($report[$table][$column]['errors'])) {
								$report[$table][$column]['errors'] = [];
							}
							$report[$table][$column]['errors'][] = $e->getMessage();

							continue;
						}
					}
				}
				
			} catch (Exception $e) {
				continue;
			}
		}
	}
	
	$endTime = microtime(true);
	$executionTime = round($endTime - $startTime, 2);
	
	return [
		'success' => true,
		'total_processed' => $totalProcessed,
		'total_updated' => $totalUpdated,
		'execution_time' => $executionTime,
		'report' => $report
	];
}

function removeTags($html, $tags) {
	if (empty($html)) {
		return $html;
	}

	$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$tagsArray = array_map('trim', explode(',', $tags));

	$patterns = [];
	$replacements = [];
	
	foreach ($tagsArray as $tagPattern) {
		if (preg_match('/^(\w+)\[([^\]]+)\]$/', $tagPattern, $matches)) {
			$tagName = $matches[1];
			$attribute = $matches[2];
			
			if (preg_match('/(\w+)\*="([^"]+)"/', $attribute, $attrMatches)) {
				$patterns[] = '/<' . preg_quote($tagName, '/') . '\s+([^>]*\s+)?' . 
							 preg_quote($attrMatches[1], '/') . '\s*=\s*"[^"]*' . 
							 preg_quote($attrMatches[2], '/') . '[^"]*"[^>]*>(.*?)<\/' . 
							 preg_quote($tagName, '/') . '>/is';
				$replacements[] = '$2';
			}
			elseif (preg_match('/(\w+)="([^"]+)"/', $attribute, $attrMatches)) {
				$patterns[] = '/<' . preg_quote($tagName, '/') . '\s+([^>]*\s+)?' . 
							 preg_quote($attrMatches[1], '/') . '\s*=\s*"' . 
							 preg_quote($attrMatches[2], '/') . '"[^>]*>(.*?)<\/' . 
							 preg_quote($tagName, '/') . '>/is';
				$replacements[] = '$2';
			}
		} else {
			$tagName = trim($tagPattern);
			$patterns[] = '/<' . preg_quote($tagName, '/') . '(\s+[^>]*)?\s*>(.*?)<\/' . 
						 preg_quote($tagName, '/') . '\s*>/is';
			$replacements[] = '$2';
			$patterns[] = '/<' . preg_quote($tagName, '/') . '(\s+[^>]*)?\s*\/\s*>/i';
			$replacements[] = '';
		}
	}

	$maxIterations = 10;
	for ($i = 0; $i < $maxIterations; $i++) {
		$before = $decoded;
		$decoded = preg_replace($patterns, $replacements, $decoded);
		if ($before === $decoded) break;
	}

	return htmlspecialchars($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Універсальний очищувач HTML тегів - OpenCart</title>
	<style>
		:root {
			--primary: #6366f1;
			--primary-dark: #4f46e5;
			--primary-light: #818cf8;
			--success: #10b981;
			--warning: #f59e0b;
			--danger: #ef4444;
			--info: #06b6d4;
			--dark: #1e293b;
			--gray-50: #f8fafc;
			--gray-100: #f1f5f9;
			--gray-200: #e2e8f0;
			--gray-300: #cbd5e1;
			--gray-700: #334155;
			--gray-800: #1e293b;
			--shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
			--shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
			--shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1);
			--shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1);
			--shadow-xl: 0 25px 50px -12px rgb(0 0 0 / 0.25);
			--radius: 12px;
			--radius-lg: 16px;
		}

		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
			background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
			min-height: 100vh;
			padding: 2rem;
			line-height: 1.6;
			color: var(--gray-800);
			position: relative;
			overflow-x: hidden;
		}

		body::before {
			content: '';
			position: fixed;
			top: -50%;
			right: -50%;
			width: 200%;
			height: 200%;
			background: radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
			background-size: 50px 50px;
			animation: moveGrid 20s linear infinite;
			pointer-events: none;
		}

		@keyframes moveGrid {
			0% { transform: translate(0, 0); }
			100% { transform: translate(50px, 50px); }
		}

		.container {
			max-width: 1200px;
			margin: 0 auto;
			background: white;
			border-radius: var(--radius-lg);
			box-shadow: var(--shadow-xl);
			overflow: hidden;
			animation: slideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
			position: relative;
		}

		@keyframes slideIn {
			from {
				opacity: 0;
				transform: translateY(30px) scale(0.95);
			}
			to {
				opacity: 1;
				transform: translateY(0) scale(1);
			}
		}

		.header {
			background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
			color: white;
			padding: 3rem 2rem;
			text-align: center;
			position: relative;
			overflow: hidden;
		}

		.header::before {
			content: '';
			position: absolute;
			top: -50%;
			left: -50%;
			width: 200%;
			height: 200%;
			background: radial-gradient(circle at 50% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
			animation: rotate 10s linear infinite;
		}

		@keyframes rotate {
			from { transform: rotate(0deg); }
			to { transform: rotate(360deg); }
		}

		.header h1 {
			font-size: 2rem;
			font-weight: 700;
			margin-bottom: 0.75rem;
			position: relative;
			z-index: 1;
			letter-spacing: -0.025em;
		}

		.header p {
			opacity: 0.95;
			font-size: 1rem;
			position: relative;
			z-index: 1;
			font-weight: 400;
		}

		.content {
			padding: 2rem;
		}

		.settings-box {
			background: var(--gray-50);
			border: 1px solid var(--gray-200);
			border-radius: var(--radius);
			padding: 1.5rem;
			margin-bottom: 1.5rem;
			transition: all 0.3s ease;
		}

		.settings-box:hover {
			border-color: var(--gray-300);
			box-shadow: var(--shadow);
		}

		.settings-box h2 {
			color: var(--dark);
			margin-bottom: 1.25rem;
			font-size: 1.25rem;
			font-weight: 600;
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}

		.settings-box h2::before {
			content: '🎯';
			font-size: 1.5rem;
		}

		.form-group {
			margin-bottom: 1.25rem;
		}

		.form-group:last-child {
			margin-bottom: 0;
		}

		.form-group label {
			display: block;
			margin-bottom: 0.5rem;
			color: var(--gray-700);
			font-weight: 500;
			font-size: 0.875rem;
			letter-spacing: 0.01em;
		}

		.form-group input,
		.form-group select {
			width: 100%;
			padding: 0.75rem 1rem;
			border: 2px solid var(--gray-200);
			border-radius: 0.5rem;
			font-size: 0.9375rem;
			transition: all 0.2s ease;
			background: white;
			font-family: inherit;
		}

		.form-group input:focus,
		.form-group select:focus {
			outline: none;
			border-color: var(--primary);
			box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
		}

		.form-group input:hover,
		.form-group select:hover {
			border-color: var(--gray-300);
		}

		.form-group small {
			display: block;
			margin-top: 0.5rem;
			color: var(--gray-700);
			font-size: 0.8125rem;
			line-height: 1.5;
		}

		.btn {
			background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
			color: white;
			border: none;
			padding: 0.875rem 1.5rem;
			border-radius: 0.5rem;
			font-size: 1rem;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
			width: 100%;
			position: relative;
			overflow: hidden;
			box-shadow: var(--shadow);
		}

		.btn::before {
			content: '';
			position: absolute;
			top: 0;
			left: -100%;
			width: 100%;
			height: 100%;
			background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
			transition: left 0.5s;
		}

		.btn:hover::before {
			left: 100%;
		}

		.btn:hover {
			transform: translateY(-2px);
			box-shadow: var(--shadow-md);
		}

		.btn:active {
			transform: translateY(0);
			box-shadow: var(--shadow-sm);
		}

		.btn:disabled {
			opacity: 0.6;
			cursor: not-allowed;
			transform: none;
		}

		.btn:disabled:hover {
			box-shadow: var(--shadow);
		}

		.results {
			margin-top: 1.5rem;
		}

		.info-box, .warning-box, .success-box, .error-box {
			padding: 1rem 1.25rem;
			border-radius: 0.5rem;
			margin-bottom: 1rem;
			animation: fadeSlideIn 0.4s ease-out;
			border-left: 4px solid;
			font-size: 0.9375rem;
		}

		@keyframes fadeSlideIn {
			from {
				opacity: 0;
				transform: translateY(-10px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}

		.info-box {
			background: #eff6ff;
			border-color: var(--info);
			color: #0c4a6e;
		}

		.warning-box {
			background: #fef3c7;
			border-color: var(--warning);
			color: #92400e;
		}

		.success-box {
			background: #d1fae5;
			border-color: var(--success);
			color: #065f46;
		}

		.error-box {
			background: #fee2e2;
			border-color: var(--danger);
			color: #991b1b;
		}

		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 1rem;
			margin: 1.5rem 0;
		}

		.stat-card {
			background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
			color: white;
			padding: 2rem 1.5rem;
			border-radius: var(--radius);
			text-align: center;
			box-shadow: var(--shadow-md);
			transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
			position: relative;
			overflow: hidden;
		}

		.stat-card::before {
			content: '';
			position: absolute;
			top: -50%;
			right: -50%;
			width: 200%;
			height: 200%;
			background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
			transition: transform 0.5s ease;
		}

		.stat-card:hover {
			transform: translateY(-5px);
			box-shadow: var(--shadow-lg);
		}

		.stat-card:hover::before {
			transform: rotate(45deg);
		}

		.stat-card .number {
			font-size: 2.5rem;
			font-weight: 700;
			margin-bottom: 0.5rem;
			position: relative;
			z-index: 1;
			letter-spacing: -0.02em;
		}

		.stat-card .label {
			font-size: 0.875rem;
			opacity: 0.95;
			position: relative;
			z-index: 1;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			font-weight: 500;
		}

		.table-report {
			margin: 1rem 0;
			border: 1px solid var(--gray-200);
			border-radius: var(--radius);
			overflow: hidden;
			box-shadow: var(--shadow-sm);
			transition: all 0.3s ease;
			background: white;
		}

		.table-report:hover {
			box-shadow: var(--shadow);
			border-color: var(--gray-300);
		}

		.table-header {
			background: var(--gray-50);
			padding: 1rem 1.25rem;
			border-bottom: 1px solid var(--gray-200);
			font-weight: 600;
			color: var(--dark);
			display: flex;
			align-items: center;
			gap: 0.75rem;
			font-size: 0.9375rem;
		}

		.table-header input[type="checkbox"] {
			width: 1.125rem;
			height: 1.125rem;
			cursor: pointer;
			accent-color: var(--primary);
			transition: transform 0.2s ease;
		}

		.table-header input[type="checkbox"]:hover {
			transform: scale(1.1);
		}

		.table-body {
			padding: 1.25rem;
		}

		.column-info {
			margin-bottom: 1rem;
			padding-bottom: 1rem;
			border-bottom: 1px solid var(--gray-200);
		}

		.column-info:last-child {
			border-bottom: none;
			margin-bottom: 0;
			padding-bottom: 0;
		}

		.loader {
			border: 4px solid var(--gray-200);
			border-top: 4px solid var(--primary);
			border-radius: 50%;
			width: 3rem;
			height: 3rem;
			animation: spin 1s linear infinite;
			margin: 2rem auto;
		}

		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}

		details {
			margin: 1rem 0;
		}

		details summary {
			padding: 0.75rem 1rem;
			background: var(--gray-50);
			border-radius: 0.5rem;
			margin-bottom: 0.5rem;
			cursor: pointer;
			user-select: none;
			transition: all 0.2s ease;
			border: 1px solid var(--gray-200);
			font-weight: 500;
			color: var(--gray-700);
		}

		details summary:hover {
			background: var(--gray-100);
			border-color: var(--gray-300);
			transform: translateX(3px);
		}

		details[open] summary {
			margin-bottom: 0.75rem;
			border-bottom: 2px solid var(--primary);
			border-radius: 0.5rem 0.5rem 0 0;
			background: white;
		}

		code {
			background: var(--gray-100);
			padding: 0.25rem 0.5rem;
			border-radius: 0.25rem;
			font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
			font-size: 0.875rem;
			color: var(--primary-dark);
			border: 1px solid var(--gray-200);
			font-weight: 500;
		}

		.template-btn {
			padding: 0.625rem 1rem;
			background: white;
			border: 2px solid var(--gray-200);
			border-radius: 0.5rem;
			cursor: pointer;
			font-size: 0.875rem;
			transition: all 0.2s ease;
			font-weight: 500;
			color: var(--gray-700);
			box-shadow: var(--shadow-sm);
		}

		.template-btn:hover {
			background: var(--gray-50);
			border-color: var(--primary);
			transform: translateY(-2px);
			box-shadow: var(--shadow);
			color: var(--primary);
		}

		.template-btn:active {
			transform: translateY(0);
			box-shadow: var(--shadow-sm);
		}

		@media (max-width: 768px) {
			body {
				padding: 1rem;
			}

			.container {
				border-radius: var(--radius);
			}

			.header {
				padding: 2rem 1.5rem;
			}

			.header h1 {
				font-size: 1.5rem;
			}

			.header p {
				font-size: 0.875rem;
			}

			.content {
				padding: 1.5rem;
			}

			.settings-box {
				padding: 1.25rem;
			}

			.stats-grid {
				grid-template-columns: 1fr;
			}

			.stat-card .number {
				font-size: 2rem;
			}

			.btn {
				padding: 0.75rem 1.25rem;
				font-size: 0.9375rem;
			}

			.template-btn {
				padding: 0.5rem 0.75rem;
				font-size: 0.8125rem;
			}
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>🧹 Універсальний очищувач HTML тегів</h1>
			<p>Автоматичне видалення будь-яких HTML тегів з полів description в таблицях OpenCart/ocStore</p>
		</div>
		<div class="content">
			<div class="warning-box">
				<strong>⚠️ УВАГА!</strong> Перед використанням обов'язково зробіть резервну копію бази даних!
			</div>
			<div class="settings-box">
				<h2>Теги для видалення</h2>
				<div class="form-group">
					<label>Вкажіть HTML теги (через кому):</label>
					<input type="text" id="tags_to_remove" value="" placeholder="span, strong, em, font">
					<small style="display: block; margin-top: 5px; color: #6c757d;">
						Вкажіть HTML теги для видалення (без < >). Приклади:<br>
						• <strong>span</strong> - видалить всі &lt;span&gt;...&lt;/span&gt;<br>
						• <strong>span[style*="color"]</strong> - тільки span з атрибутом style що містить "color"<br>
						• <strong>font, strong, em</strong> - видалить всі ці теги
					</small>
				</div>
				<div class="form-group">
					<label>Швидкі шаблони:</label>
					<div style="display: flex; gap: 8px; flex-wrap: wrap;">
						<button type="button" onclick="applyTemplate('span[style*=&quot;color&quot;]')" class="template-btn">
							🎨 Кольорові span
						</button>
						<button type="button" onclick="applyTemplate('span, strong, em, b, i, u')" class="template-btn">
							📝 Форматування тексту
						</button>
						<button type="button" onclick="applyTemplate('font')" class="template-btn">
							🔤 Старі font теги
						</button>
						<button type="button" onclick="applyTemplate('div, p')" class="template-btn">
							📦 Блокові елементи
						</button>
					</div>
				</div>
				<button class="btn" id="scanBtn" onclick="scanDatabase()">🔍 Сканувати базу даних</button>
			</div>
			<div id="results" class="results"></div>
		</div>
	</div>

	<script>
		let scanData = null;
		let selectedTablesList = new Set();

		function toggleTable(tableName) {
			const safeTableId = tableName.replace(/[^a-zA-Z0-9_-]/g, '_');
			const checkbox = document.getElementById(`table_${tableName}`);

			if (checkbox && checkbox.checked) {
				selectedTablesList.add(tableName);
			} else {
				selectedTablesList.delete(tableName);
			}

			console.log('Selected tables:', Array.from(selectedTablesList));
			updateSelectedCount();
		}

		function selectAllTables(select) {
			if (!scanData || !scanData.tables) return;

			Object.keys(scanData.tables).forEach(tableName => {
				const checkbox = document.getElementById(`table_${tableName}`);
				if (checkbox) {
					checkbox.checked = select;
					if (select) {
						selectedTablesList.add(tableName);
					} else {
						selectedTablesList.delete(tableName);
					}
				}
			});

			updateSelectedCount();
		}

		function updateSelectedCount() {
			const countElement = document.getElementById('selectedCount');
			if (countElement) {
				countElement.textContent = selectedTablesList.size;
			}
		}

		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		function applyTemplate(tags) {
			const input = document.getElementById('tags_to_remove');
			const currentValue = input.value.trim();

			if (currentValue && !confirm('Замінити поточне значення?')) {
				const existingTags = currentValue.split(',').map(t => t.trim()).filter(t => t);
				const newTags = tags.split(',').map(t => t.trim()).filter(t => t);

				const tagsToAdd = newTags.filter(tag => !existingTags.includes(tag));

				if (tagsToAdd.length === 0) {
					input.style.background = '#fff3cd';
					setTimeout(() => {
						input.style.background = '';
					}, 500);
					return;
				}

				input.value = [...existingTags, ...tagsToAdd].join(', ');
			} else {
				input.value = tags;
			}

			input.style.background = '#d4edda';
			setTimeout(() => {
				input.style.background = '';
			}, 500);
		}

		async function scanDatabase() {
			const btn = document.getElementById('scanBtn');
			const results = document.getElementById('results');

			const tags = document.getElementById('tags_to_remove').value.trim();

			if (!tags) {
				results.innerHTML = '<div class="error-box"><strong>❌ Помилка:</strong> Вкажіть хоча б один тег для пошуку!</div>';
				return;
			}

			btn.disabled = true;
			btn.textContent = '⏳ Сканування...';

			const formData = new FormData();
			formData.append('action', 'scan');
			formData.append('tags_to_remove', tags);

			try {
				const response = await fetch(window.location.href, {
					method: 'POST',
					body: formData
				});

				const text = await response.text();

				let data;
				try {
					data = JSON.parse(text);
				} catch (e) {
					results.innerHTML = `<div class="error-box"><strong>❌ Помилка парсингу:</strong> Сервер повернув некоректну відповідь. <details><summary>Показати відповідь</summary><pre>${text}</pre></details></div>`;
					btn.disabled = false;
					btn.textContent = '🔍 Сканувати базу даних';
					return;
				}

				if (data.success) {
					scanData = data;
					displayScanResults(data);
				} else {
					results.innerHTML = `<div class="error-box"><strong>❌ Помилка:</strong> ${data.error}</div>`;
				}
			} catch (error) {
				console.error('Error:', error);
				results.innerHTML = `
					<div class="error-box">
						<strong>❌ Помилка з'єднання:</strong> ${error.message}
						<details style="margin-top: 10px;">
							<summary style="cursor: pointer; color: #721c24;">Технічні деталі</summary>
							<pre style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px; font-size: 12px; overflow-x: auto;">${error.stack || 'Стек недоступний'}</pre>
						</details>
					</div>
				`;
			}

			btn.disabled = false;
			btn.textContent = '🔍 Сканувати базу даних';
		}

		function displayScanResults(data) {
			const results = document.getElementById('results');

			const htmlParts = [];

			selectedTablesList.clear();
			for (const table of Object.keys(data.tables)) {
				selectedTablesList.add(table);
			}

			htmlParts.push(`
				<div class="info-box">
					<strong>✓ Сканування завершено!</strong><br>
					<small>Пошук тегів: <code>${data.tags_searched.join(', ')}</code></small>
				</div>
				<div class="stats-grid">
					<div class="stat-card"><div class="number">${data.tables_count}</div><div class="label">Таблиць знайдено</div></div>
					<div class="stat-card"><div class="number">${data.total_records}</div><div class="label">Записів для обробки</div></div>
				</div>
			`);

			if (data.total_records > 0) {
				htmlParts.push(`
					<h3 style="margin: 20px 0 10px;">Знайдені таблиці та стовпці:</h3>
					<div style="margin-bottom: 5px; padding: 10px; background: #f8f9fa; border-radius: 6px; display: flex; gap: 10px; align-items: center;">
						<button onclick="selectAllTables(true)" style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
							✓ Вибрати всі
						</button>
						<button onclick="selectAllTables(false)" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
							✗ Зняти всі
						</button>
						<span style="margin-left: auto; color: #6c757d; font-weight: 600;">
							Вибрано: <span id="selectedCount">${selectedTablesList.size}</span> / ${data.tables_count}
						</span>
					</div>
				`);

				for (const [table, columns] of Object.entries(data.tables)) {
					const safeTableName = table.replace(/[^a-zA-Z0-9_-]/g, '_');

					htmlParts.push(`
						<div class="table-report">
							<div class="table-header">
								<input type="checkbox" id="table_${safeTableName}" checked onchange="toggleTable('${table.replace(/'/g, "\\'")}')">
								📋 ${table}
							</div>
							<div class="table-body">
					`);

					for (const [column, columnData] of Object.entries(columns)) {
						htmlParts.push(`
							<div class="column-info">
								<strong>${column}:</strong> ${columnData.count} записів містять вказані теги
								<details style="margin-top: 10px;">
									<summary style="cursor: pointer; color: #667eea; font-weight: 600;">Показати ID записів (${columnData.ids.length})</summary>
									<div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; max-height: 200px; overflow-y: auto;">
						`);

						const formattedIds = columnData.ids.map(id => {
							if (typeof id === 'object' && id !== null) {
								return Object.entries(id)
									.map(([key, value]) => `${key}=${value}`)
									.join(', ');
							} else if (id === 'N/A') {
								return 'N/A';
							} else {
								return id;
							}
						});

						htmlParts.push(formattedIds.join('<br>'));
						htmlParts.push(`</div></details>`);

						if (columnData.preview) {
							htmlParts.push(`
								<details style="margin-top: 10px;">
									<summary style="cursor: pointer; color: #fd7e14; font-weight: 600;">👁️ Приклад змін (перший запис)</summary>
									<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px;">
										<div style="margin-bottom: 10px;">
											<strong style="color: #dc3545;">ДО:</strong>
											<div style="padding: 8px; background: white; border: 1px solid #dee2e6; border-radius: 4px; margin-top: 5px; max-height: 150px; overflow-y: auto; font-size: 12px; word-break: break-all;">
												${escapeHtml(columnData.preview.before)}
											</div>
										</div>
										<div>
											<strong style="color: #28a745;">ПІСЛЯ:</strong>
											<div style="padding: 8px; background: white; border: 1px solid #dee2e6; border-radius: 4px; margin-top: 5px; max-height: 150px; overflow-y: auto; font-size: 12px; word-break: break-all;">
												${escapeHtml(columnData.preview.after)}
											</div>
										</div>
									</div>
								</details>
							`);
						}

						htmlParts.push(`</div>`);
					}

					htmlParts.push(`</div></div>`);
				}

				htmlParts.push('<button class="btn" onclick="startCleaning()">🚀 Почати очистку</button>');
			} else {
				htmlParts.push('<div class="success-box"><strong>✓ Не знайдено записів з вказаними тегами.</strong> База даних чиста!</div>');
			}

			results.innerHTML = htmlParts.join('');
		}

		async function startCleaning() {
			const results = document.getElementById('results');

			if (selectedTablesList.size === 0) {
				const statsGrid = results.querySelector('.stats-grid');
				const errorDiv = document.createElement('div');
				errorDiv.className = 'error-box';
				errorDiv.innerHTML = '<strong>❌ Помилка:</strong> Виберіть хоча б одну таблицю для очистки!';
				errorDiv.style.marginBottom = '20px';

				statsGrid.insertAdjacentElement('afterend', errorDiv);

				setTimeout(() => {
					errorDiv.remove();
				}, 5000);

				errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

				setTimeout(() => {
					const offset = 210;
					const top = errorDiv.getBoundingClientRect().top + window.scrollY - offset;

					window.scrollTo({
						top,
						behavior: 'smooth'
					});
				}, 50);

				return;
			}

			const selectedCount = selectedTablesList.size;
			const totalTables = scanData ? Object.keys(scanData.tables).length : 0;
			if (!confirm(`Ви збираєтеся очистити ${selectedCount} з ${totalTables} таблиць. Продовжити?`)) {
				return;
			}

			results.innerHTML = '<div class="info-box"><strong>⏳ Обробка записів...</strong></div><div class="loader"></div>';

			const formData = new FormData();
			formData.append('action', 'clean');
			formData.append('tags_to_remove', document.getElementById('tags_to_remove').value);
			formData.append('selected_tables', JSON.stringify(Array.from(selectedTablesList)));

			console.log('Sending selected tables:', Array.from(selectedTablesList));

			try {
				const response = await fetch(window.location.href, {
					method: 'POST',
					body: formData
				});

				const text = await response.text();
				const data = JSON.parse(text);

				if (data.success) {
					displayCleanResults(data);
				} else {
					results.innerHTML = `<div class="error-box"><strong>❌ Помилка:</strong> ${data.error}</div>`;
				}
			} catch (error) {
				results.innerHTML = `<div class="error-box"><strong>❌ Помилка:</strong> ${error.message}</div>`;
			}
		}

		function displayCleanResults(data) {
			const results = document.getElementById('results');

			const htmlParts = [];

			htmlParts.push(`
				<div class="success-box"><strong>✅ Очистка завершена успішно!</strong></div>
				<div class="stats-grid">
					<div class="stat-card"><div class="number">${data.total_processed}</div><div class="label">Оброблено записів</div></div>
					<div class="stat-card"><div class="number">${data.total_updated}</div><div class="label">Оновлено записів</div></div>
					<div class="stat-card"><div class="number">${data.execution_time}</div><div class="label">Секунд</div></div>
				</div>
				<h3 style="margin: 20px 0;">Звіт:</h3>
			`);

			for (const [table, columns] of Object.entries(data.report)) {
				htmlParts.push(`
					<div class="table-report">
						<div class="table-header">📋 ${table}</div>
						<div class="table-body">
				`);

				for (const [column, stats] of Object.entries(columns)) {
					if (stats.processed === 0) continue;

					htmlParts.push(`
						<div class="column-info">
							<strong>${column}:</strong> Оброблено: ${stats.processed}, Оновлено: ${stats.updated}
						</div>
					`);
				}

				htmlParts.push(`</div></div>`);
			}

			htmlParts.push('<button class="btn" style="margin-top: 10px; background: #6c757d;" onclick="location.reload()">🔄 Нове сканування</button>');
			results.innerHTML = htmlParts.join('');
			window.reportData = data;
		}
	</script>
</body>
</html>