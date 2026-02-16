<?php
/**
 * AI Features Demo Page
 * Demonstrasi penggunaan fitur-fitur AI dalam sistem hotel
 */

require_once 'includes/config.php';
require_once 'includes/AIHotelService.php';

$aiService = new AIHotelService();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'guest_response':
            $message = $_POST['message'] ?? '';
            $guestId = $_POST['guest_id'] ?? null;
            $result = $aiService->generateGuestResponse($message, $guestId);
            echo json_encode($result);
            break;
            
        case 'analyze_review':
            $reviewText = $_POST['review_text'] ?? '';
            $rating = $_POST['rating'] ?? 5;
            $platform = $_POST['platform'] ?? '';
            $result = $aiService->analyzeAndRespondToReview($reviewText, $rating, $platform);
            echo json_encode($result);
            break;
            
        case 'personalized_welcome':
            $reservationId = $_POST['reservation_id'] ?? '';
            $result = $aiService->generatePersonalizedWelcome($reservationId);
            echo json_encode($result);
            break;
            
        case 'revenue_insights':
            $dateFrom = $_POST['date_from'] ?? date('Y-m-01');
            $dateTo = $_POST['date_to'] ?? date('Y-m-d');
            $result = $aiService->generateRevenueInsights($dateFrom, $dateTo);
            echo json_encode($result);
            break;
            
        case 'daily_report':
            $date = $_POST['date'] ?? date('Y-m-d');
            $result = $aiService->generateDailyReport($date);
            echo json_encode($result);
            break;
            
        case 'predict_preferences':
            $guestId = $_POST['guest_id'] ?? '';
            $result = $aiService->predictGuestPreferences($guestId);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// Check if OpenAI is configured
$openaiConfigured = (new OpenAIHelper())->isAvailable();
$cloudbedConfigured = (new CloudbedHelper())->isAvailable();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Features Demo - ADF Hotel System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .feature-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .feature-card:hover {
            transform: translateY(-2px);
        }
        .result-container {
            min-height: 100px;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
        }
        .loading {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .demo-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-robot"></i> AI Features Demo</h1>
                    <div>
                        <span class="badge bg-<?php echo $openaiConfigured ? 'success' : 'warning'; ?>">
                            <i class="fas fa-brain"></i> OpenAI: <?php echo $openaiConfigured ? 'Connected' : 'Not Configured'; ?>
                        </span>
                        <span class="badge bg-<?php echo $cloudbedConfigured ? 'success' : 'warning'; ?>">
                            <i class="fas fa-cloud"></i> Cloudbed: <?php echo $cloudbedConfigured ? 'Connected' : 'Not Configured'; ?>
                        </span>
                    </div>
                </div>
                
                <?php if (!$openaiConfigured): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>OpenAI not configured:</strong> Please configure your OpenAI API key in 
                    <a href="modules/settings/api-integrations.php">API Integrations Settings</a> to use AI features.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Smart Guest Assistant -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-comments"></i> Smart Guest Assistant</h3>
                    <p class="text-muted">Generate AI responses for guest inquiries</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Guest Message:</label>
                        <textarea class="form-control" id="guest-message" rows="3" placeholder="Type guest inquiry here...">What time is checkout? Do you have room service?</textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Guest ID (Optional):</label>
                        <input type="number" class="form-control" id="guest-id" placeholder="Enter guest ID for personalized response">
                    </div>
                    
                    <button class="btn btn-primary" onclick="generateGuestResponse()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-magic"></i> Generate Response
                    </button>
                    
                    <div class="result-container" id="guest-response-result">
                        <span class="text-muted">AI response will appear here</span>
                    </div>
                </div>
            </div>

            <!-- Review Analysis -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-star"></i> Review Analysis & Response</h3>
                    <p class="text-muted">Analyze guest reviews and generate professional responses</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Review Text:</label>
                        <textarea class="form-control" id="review-text" rows="3" placeholder="Enter guest review...">The hotel room was clean but the AC was too noisy. Staff was very friendly though.</textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Rating:</label>
                            <select class="form-control" id="review-rating">
                                <option value="5">5 Stars</option>
                                <option value="4">4 Stars</option>
                                <option value="3" selected>3 Stars</option>
                                <option value="2">2 Stars</option>
                                <option value="1">1 Star</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Platform:</label>
                            <select class="form-control" id="review-platform">
                                <option value="">Select platform</option>
                                <option value="Google">Google Reviews</option>
                                <option value="Booking.com">Booking.com</option>
                                <option value="Agoda">Agoda</option>
                                <option value="TripAdvisor">TripAdvisor</option>
                            </select>
                        </div>
                    </div>
                    
                    <button class="btn btn-primary mt-3" onclick="analyzeReview()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-search"></i> Analyze & Respond
                    </button>
                    
                    <div class="result-container" id="review-analysis-result">
                        <span class="text-muted">Analysis and suggested response will appear here</span>
                    </div>
                </div>
            </div>

            <!-- Personalized Welcome -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-heart"></i> Personalized Welcome Messages</h3>
                    <p class="text-muted">Generate personalized welcome messages for guests</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Reservation ID:</label>
                        <input type="number" class="form-control" id="welcome-reservation-id" placeholder="Enter reservation ID">
                    </div>
                    
                    <button class="btn btn-primary" onclick="generateWelcome()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-envelope"></i> Generate Welcome Message
                    </button>
                    
                    <div class="result-container" id="welcome-result">
                        <span class="text-muted">Personalized welcome message will appear here</span>
                    </div>
                </div>
            </div>

            <!-- Revenue Insights -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-chart-line"></i> Revenue Optimization Insights</h3>
                    <p class="text-muted">Get AI-powered revenue recommendations</p>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">From Date:</label>
                            <input type="date" class="form-control" id="revenue-date-from" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Date:</label>
                            <input type="date" class="form-control" id="revenue-date-to" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <button class="btn btn-primary mt-3" onclick="generateRevenueInsights()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-lightbulb"></i> Get Insights
                    </button>
                    
                    <div class="result-container" id="revenue-insights-result">
                        <span class="text-muted">Revenue optimization insights will appear here</span>
                    </div>
                </div>
            </div>

            <!-- Daily Report -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-file-alt"></i> AI Daily Report</h3>
                    <p class="text-muted">Generate comprehensive daily reports with AI insights</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Report Date:</label>
                        <input type="date" class="form-control" id="report-date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <button class="btn btn-primary" onclick="generateDailyReport()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-file-export"></i> Generate Report
                    </button>
                    
                    <div class="result-container" id="daily-report-result">
                        <span class="text-muted">AI-generated report will appear here</span>
                    </div>
                </div>
            </div>

            <!-- Guest Preferences Prediction -->
            <div class="col-md-6 mb-4">
                <div class="demo-section">
                    <h3><i class="fas fa-user-cog"></i> Guest Preference Prediction</h3>
                    <p class="text-muted">Predict guest preferences based on history</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Guest ID:</label>
                        <input type="number" class="form-control" id="predict-guest-id" placeholder="Enter guest ID with booking history">
                    </div>
                    
                    <button class="btn btn-primary" onclick="predictPreferences()" <?php echo !$openaiConfigured ? 'disabled' : ''; ?>>
                        <i class="fas fa-crystal-ball"></i> Predict Preferences
                    </button>
                    
                    <div class="result-container" id="preferences-result">
                        <span class="text-muted">Predicted preferences will appear here</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuration Help -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle"></i> Quick Setup Guide:</h5>
                    <ol>
                        <li>Go to <a href="modules/settings/api-integrations.php" target="_blank">API Integrations Settings</a></li>
                        <li>Enter your OpenAI API key (get it from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Dashboard</a>)</li>
                        <li>Configure Cloudbed credentials if you have a Cloudbed account</li>
                        <li>Run the database setup: <code>php check-db.php</code> to create necessary tables</li>
                        <li>Test the features using this demo page</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showLoading(containerId) {
            document.getElementById(containerId).innerHTML = '<div class="loading"></div>';
        }

        function showResult(containerId, content, isError = false) {
            const container = document.getElementById(containerId);
            container.innerHTML = `<div class="alert ${isError ? 'alert-danger' : 'alert-success'} mb-0">${content}</div>`;
        }

        function generateGuestResponse() {
            const message = document.getElementById('guest-message').value;
            const guestId = document.getElementById('guest-id').value;
            
            if (!message.trim()) {
                alert('Please enter a guest message');
                return;
            }
            
            showLoading('guest-response-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=guest_response&message=${encodeURIComponent(message)}&guest_id=${guestId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const response = data.data.choices[0].message.content;
                    showResult('guest-response-result', `<strong>AI Response:</strong><br>${response}`);
                } else {
                    showResult('guest-response-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('guest-response-result', `Error: ${error.message}`, true);
            });
        }

        function analyzeReview() {
            const reviewText = document.getElementById('review-text').value;
            const rating = document.getElementById('review-rating').value;
            const platform = document.getElementById('review-platform').value;
            
            if (!reviewText.trim()) {
                alert('Please enter a review text');
                return;
            }
            
            showLoading('review-analysis-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=analyze_review&review_text=${encodeURIComponent(reviewText)}&rating=${rating}&platform=${platform}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const analysis = data.analysis;
                    const response = data.suggested_response;
                    const result = `
                        <strong>Sentiment:</strong> ${analysis.sentiment} (Score: ${analysis.score}/10)<br>
                        <strong>Key Points:</strong> ${analysis.key_points ? analysis.key_points.join(', ') : 'N/A'}<br>
                        <hr>
                        <strong>Suggested Response:</strong><br>${response}
                    `;
                    showResult('review-analysis-result', result);
                } else {
                    showResult('review-analysis-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('review-analysis-result', `Error: ${error.message}`, true);
            });
        }

        function generateWelcome() {
            const reservationId = document.getElementById('welcome-reservation-id').value;
            
            if (!reservationId) {
                alert('Please enter a reservation ID');
                return;
            }
            
            showLoading('welcome-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=personalized_welcome&reservation_id=${reservationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showResult('welcome-result', `<strong>Personalized Welcome:</strong><br>${data.message}`);
                } else {
                    showResult('welcome-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('welcome-result', `Error: ${error.message}`, true);
            });
        }

        function generateRevenueInsights() {
            const dateFrom = document.getElementById('revenue-date-from').value;
            const dateTo = document.getElementById('revenue-date-to').value;
            
            if (!dateFrom || !dateTo) {
                alert('Please select both dates');
                return;
            }
            
            showLoading('revenue-insights-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=revenue_insights&date_from=${dateFrom}&date_to=${dateTo}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showResult('revenue-insights-result', `<strong>Revenue Insights:</strong><br>${data.insights}`);
                } else {
                    showResult('revenue-insights-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('revenue-insights-result', `Error: ${error.message}`, true);
            });
        }

        function generateDailyReport() {
            const date = document.getElementById('report-date').value;
            
            if (!date) {
                alert('Please select a date');
                return;
            }
            
            showLoading('daily-report-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=daily_report&date=${date}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const report = data.report;
                    const aiSummary = data.ai_summary || 'No AI summary available';
                    
                    const result = `
                        <strong>Report for ${report.date}:</strong><br>
                        <strong>Occupancy:</strong> ${report.occupancy.occupancy_rate}%<br>
                        <strong>Revenue:</strong> Rp ${new Intl.NumberFormat('id-ID').format(report.revenue.total_revenue)}<br>
                        <strong>Guests:</strong> ${report.guests.total_guests}<br>
                        <hr>
                        <strong>AI Summary:</strong><br>${aiSummary}
                    `;
                    showResult('daily-report-result', result);
                } else {
                    showResult('daily-report-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('daily-report-result', `Error: ${error.message}`, true);
            });
        }

        function predictPreferences() {
            const guestId = document.getElementById('predict-guest-id').value;
            
            if (!guestId) {
                alert('Please enter a guest ID');
                return;
            }
            
            showLoading('preferences-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=predict_preferences&guest_id=${guestId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const predictions = data.data.choices[0].message.content;
                    showResult('preferences-result', `<strong>Predicted Preferences:</strong><br>${predictions}`);
                } else {
                    showResult('preferences-result', `Error: ${data.error}`, true);
                }
            })
            .catch(error => {
                showResult('preferences-result', `Error: ${error.message}`, true);
            });
        }
    </script>
</body>
</html>