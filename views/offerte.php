<div class="main-container">
    <h1>Panoramica Offerte</h1>
    
    <div class="overview-section">
        <h2>Processi Offerte</h2>
        <p>Qui puoi accedere ai vari processi relativi alla gestione delle offerte.</p>
        
        <!-- Sezione per accedere ai processi -->
        <div class="process-buttons">
            <a href="index.php?page=crea_offerta" class="btn btn-primary">Crea Nuova Offerta</a>
            <a href="index.php?page=gestione_richiesta" class="btn btn-primary">Gestione Richieste d'Offerta</a>
            <a href="index.php?page=riesame_offerta" class="btn btn-primary">Riesame Offerte</a>
        </div>
    </div>

    <!-- Sezione panoramica offerte -->
    <div class="offers-list">
        <h2>Elenco Offerte</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Numero Offerta</th>
                    <th>Cliente</th>
                    <th>Data Offerta</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <!-- Qui verranno popolati i dati delle offerte -->
                <?php foreach($offers as $offer): ?>
                    <tr>
                        <td><?= $offer['offer_number']; ?></td>
                        <td><?= $offer['client']; ?></td>
                        <td><?= $offer['offer_date']; ?></td>
                        <td><?= $offer['status']; ?></td>
                        <td>
                            <a href="index.php?page=dettagli_offerta&id=<?= $offer['id']; ?>" class="btn btn-info">Dettagli</a>
                            <a href="index.php?page=modifica_offerta&id=<?= $offer['id']; ?>" class="btn btn-warning">Modifica</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
