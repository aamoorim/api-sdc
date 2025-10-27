<?php

/**
 * Registra um log de auditoria no banco de dados.
 *
 * @param PDO $pdo Conexão PDO com o banco.
 * @param int $id_autor ID do usuário que executou a ação.
 * @param string $acao Tipo de ação (ex: "criar", "editar", "deletar", "visualizar").
 * @param string $descricao Descrição detalhada da ação.
 * @param mixed|null $valor_antigo Valor antigo antes da alteração (opcional).
 * @param mixed|null $valor_novo Valor novo após a alteração (opcional).
 */
function registrarLogAuditoria($pdo, $id_autor, $acao, $descricao, $valor_antigo = null, $valor_novo = null) {
    try {
        // Converter os valores para JSON, mas garantir que não quebre se for null
        $valor_antigo_json = $valor_antigo !== null ? json_encode($valor_antigo) : null;
        $valor_novo_json = $valor_novo !== null ? json_encode($valor_novo) : null;

        // Checar se a conversão JSON deu erro
        if ($valor_antigo !== null && $valor_antigo_json === false) {
            error_log("Falha ao converter valor_antigo para JSON: " . json_last_error_msg());
        }
        if ($valor_novo !== null && $valor_novo_json === false) {
            error_log("Falha ao converter valor_novo para JSON: " . json_last_error_msg());
        }

        // Montar e executar o INSERT
        $stmt = $pdo->prepare("
            INSERT INTO log_auditoria (id_autor, acao, descricao, valor_antigo, valor_novo, data_criacao)
            VALUES (:id_autor, :acao, :descricao, :valor_antigo, :valor_novo, NOW())
        ");

        $params = [
            ':id_autor' => $id_autor,
            ':acao' => $acao,
            ':descricao' => $descricao,
            ':valor_antigo' => $valor_antigo_json,
            ':valor_novo' => $valor_novo_json
        ];

        // Log dos parâmetros antes do execute
        error_log("Tentativa de inserir log de auditoria: " . json_encode($params));

        $executado = $stmt->execute($params);

        if ($executado) {
            error_log("Registro de auditoria inserido com sucesso");
        } else {
            error_log("Falha ao inserir registro de auditoria: " . implode(" | ", $stmt->errorInfo()));
        }

    } catch (PDOException $e) {
        error_log("Erro ao registrar auditoria: " . $e->getMessage());
    }
}
