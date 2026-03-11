<div class="main-container">
    <div class="charts-container">
        <!-- Grafico a barre -->
        <div class="chart">
            <canvas id="barChart"></canvas>
        </div>

        <div class="chart pie-chart-container">
            <div class="pie-chart-header">
                <select id="statusFilter" onchange="updatePieChart()">
                    <option value="all" selected>Tutti gli Stati</option>
                </select>
            </div>
            <canvas id="pieChart"></canvas>
        </div>
    </div>
</div>

<!-- Inclusione di Chart.js -->
<script src="assets/js/chart.umd.js"></script>

<!-- Inclusione del file JavaScript per la gestione dei grafici -->
<script src="assets/js/dashboard.js"></script>
