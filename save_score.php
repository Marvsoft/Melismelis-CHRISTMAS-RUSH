<?php
// Disabilita display_errors per evitare output che corrompe il JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Connessione al database (configurazione Devilbox standard)
$conn = new mysqli('127.0.0.1', 'root', 'root', 'natale');

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Funzione per ottenere i top 30 punteggi
function getTopScores($conn) {
    $scores = [];
    // Ordina per score DESC, poi per game_date ASC, poi per id ASC (per determinismo quando score e data sono uguali)
    $result = $conn->query("SELECT player_name, score, game_date, is_victory, id FROM high_scores ORDER BY score DESC, game_date ASC, id ASC LIMIT 30");
    
    if ($result) {
        while($row = $result->fetch_assoc()) {
            // Converti la data dal formato del database in un oggetto DateTime
            $date = new DateTime($row['game_date']);
            
            // Array dei mesi in italiano (abbreviati)
            $mesi = array(
                1 => 'gen', 2 => 'feb', 3 => 'mar', 4 => 'apr',
                5 => 'mag', 6 => 'giu', 7 => 'lug', 8 => 'ago',
                9 => 'set', 10 => 'ott', 11 => 'nov', 12 => 'dic'
            );
            
            // Formatta la data manualmente (senza anno)
            $giorno = $date->format('j');
            $mese = $mesi[(int)$date->format('n')];
            $ora = $date->format('H:i');
            
            $formatted_date = "$giorno $mese $ora";
            $row['game_date'] = $formatted_date;
            $scores[] = $row;
        }
    }
    
    return $scores;
}

// Se riceviamo un nuovo punteggio
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['score']) && isset($data['player_name'])) {
        $score = (int)$data['score'];
        $name = substr(strtoupper($data['player_name']), 0, 3);
        
        // Verifica se il punteggio merita di entrare in classifica
        $stmt = $conn->prepare("SELECT MIN(score) as min_score FROM high_scores");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $count = $conn->query("SELECT COUNT(*) as count FROM high_scores")->fetch_assoc()['count'];
        
        if ($count < 30 || $score > $result['min_score']) {
            if ($count >= 30) {
                // Rimuovi il punteggio più basso
                $conn->query("DELETE FROM high_scores ORDER BY score ASC LIMIT 1");
            }
            
            // Inserisci il nuovo punteggio
            $stmt = $conn->prepare("INSERT INTO high_scores (player_name, score, game_date, is_victory) VALUES (?, ?, ?, ?)");
            $is_victory = isset($data['is_victory']) ? 1 : 0;
            
            // Se è una vittoria, usa la data fissa del 25 dicembre alle 20:00
            if ($is_victory) {
                $game_date = '2025-12-25 20:00:00';
            } else {
                // Preferisci la data inviata dal client (risoluzione a 1 minuto), se valida; altrimenti calcolo lineare
                $game_date = null;
                if (isset($data['game_date'])) {
                    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $data['game_date']);
                    if ($dt) {
                        $game_date = $dt->format('Y-m-d H:i:s');
                    }
                }
                if (!$game_date) {
                    // Calcolo lineare a 1 minuto sull'intera timeline (allineato al frontend)
                    $start_date = new DateTime('2025-12-01 08:00:00');
                    $end_date   = new DateTime('2025-12-25 20:00:00');
                    $total_minutes = ($end_date->getTimestamp() - $start_date->getTimestamp()) / 60;
                    $TOTAL_GAME_POINTS = 1272;
                    $progress = max(0, min($score / $TOTAL_GAME_POINTS, 1));
                    $minutes_to_add = (int) floor($progress * $total_minutes);
                    $start_date->modify('+' . $minutes_to_add . ' minutes');
                $game_date = $start_date->format('Y-m-d H:i:s');
                }
            }
            
            $stmt->bind_param("sisi", $name, $score, $game_date, $is_victory);
            $stmt->execute();
            
            // Calcola la posizione dell'utente in classifica
            // Chi ha score più alto viene prima, a parità di score chi ha game_date più vecchia (completato prima) viene prima
            // Se anche la data è uguale, chi è stato inserito prima (id minore) viene prima
            // Ottieni l'ID del record appena inserito
            $inserted_id = $conn->insert_id;
            $rank_query = $conn->query("SELECT COUNT(*) + 1 as user_rank FROM high_scores WHERE score > $score OR (score = $score AND (game_date < '$game_date' OR (game_date = '$game_date' AND id < $inserted_id)))");
            $user_rank = $rank_query->fetch_assoc()['user_rank'];
            
            // Prendi i punteggi
            $scores = getTopScores($conn);
            
            // Restituisci i punteggi con la posizione dell'utente
            echo json_encode([
                'success' => true,
                'scores' => $scores,
                'user_rank' => $user_rank,
                'user_score' => $score,
                'user_name' => $name
            ]);
            
            $conn->close();
            exit;
        } else {
            // Il punteggio non entra nei top 30
            echo json_encode([
                'success' => false,
                'message' => 'Score too low for top 30',
                'scores' => getTopScores($conn)
            ]);
            $conn->close();
            exit;
        }
    }
}

// Prendi i punteggi (per richieste GET)
$scores = getTopScores($conn);

// Debug: stampa i punteggi prima del json_encode
error_log(print_r($scores, true));

// Restituisci i punteggi
echo json_encode(['scores' => $scores]);

$conn->close();
?>