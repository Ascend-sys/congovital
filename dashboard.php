<?php
session_start();

$appDir = is_dir(__DIR__ . '/backend') ? __DIR__ : __DIR__ . '/congovital';
$assetBase = is_dir(__DIR__ . '/backend') ? '' : 'congovital/';

require_once $appDir . '/backend/config/database.php';
require_once $appDir . '/backend/models/User.php';
require_once $appDir . '/backend/models/Appointment.php';
require_once $appDir . '/backend/models/Doctor.php';
require_once $appDir . '/backend/models/HealthArticle.php';

$userId = null;
if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
} elseif (isset($_GET['user_id'])) {
    $userId = (int) $_GET['user_id'];
}

if (!$userId) {
    header('Location: ' . $assetBase . 'frontend/html/abonne.html');
    exit;
}

$userModel = new User();
$user = $userModel->findById($userId);

if (!$user) {
    header('Location: ' . $assetBase . 'frontend/html/abonne.html');
    exit;
}

$role = $user['role'];
$fullname = $user['fullname'];
$username = $user['username'];
$email = $user['email'];
$phone = $user['phone'] ?? '';
$gender = $user['gender'] ?? '';
$dateOfBirth = $user['date_of_birth'] ?? '';

$flashMessage = null;
$flashType = 'success';

if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['dashboard_action'] ?? '';
    $pdo = getConnection();

    try {
        if ($action === 'save_doctor') {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);
            $values = [
                trim($_POST['first_name'] ?? ''),
                trim($_POST['last_name'] ?? ''),
                trim($_POST['specialty'] ?? ''),
                trim($_POST['address'] ?? ''),
                trim($_POST['city'] ?? ''),
                trim($_POST['phone'] ?? ''),
                trim($_POST['opening_hours'] ?? ''),
            ];

            if ($values[0] === '' || $values[1] === '' || $values[2] === '' || $values[3] === '') {
                throw new RuntimeException('Veuillez remplir le nom, la spécialité et l’adresse du médecin.');
            }

            if ($doctorId > 0) {
                $stmt = $pdo->prepare('UPDATE doctors SET first_name=?, last_name=?, specialty=?, address=?, city=?, phone=?, opening_hours=? WHERE id=?');
                $stmt->execute([...$values, $doctorId]);
                $flashMessage = 'Médecin mis à jour.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO doctors (first_name, last_name, specialty, address, city, phone, opening_hours) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute($values);
                $flashMessage = 'Médecin ajouté.';
            }
        } elseif ($action === 'delete_doctor') {
            $stmt = $pdo->prepare('UPDATE doctors SET is_active = 0 WHERE id = ?');
            $stmt->execute([(int) ($_POST['doctor_id'] ?? 0)]);
            $flashMessage = 'Médecin retiré de l’annuaire.';
        } elseif ($action === 'save_article') {
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $content = trim($_POST['content'] ?? '');

            if ($title === '' || $category === '' || $content === '') {
                throw new RuntimeException('Veuillez remplir le titre, la catégorie et le contenu de l’article.');
            }

            if ($articleId > 0) {
                $stmt = $pdo->prepare('UPDATE health_articles SET title=?, category=?, content=? WHERE id=?');
                $stmt->execute([$title, $category, $content, $articleId]);
                $flashMessage = 'Article mis à jour.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO health_articles (title, category, content, published_at, is_published) VALUES (?, ?, ?, NOW(), 1)');
                $stmt->execute([$title, $category, $content]);
                $flashMessage = 'Article ajouté.';
            }
        } elseif ($action === 'delete_article') {
            $stmt = $pdo->prepare('UPDATE health_articles SET is_published = 0 WHERE id = ?');
            $stmt->execute([(int) ($_POST['article_id'] ?? 0)]);
            $flashMessage = 'Article retiré de la publication.';
        }
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

// Stats for patient
$appointmentModel = new Appointment();
$appointments = $appointmentModel->getByUserId($userId);
$doctorModel = new Doctor();
$articleModel = new HealthArticle();
$doctors = $doctorModel->search();
$articles = $articleModel->getPublished();
$totalAppointments = count($appointments);
$pendingAppointments = 0;
$confirmedAppointments = 0;
$completedAppointments = 0;
foreach ($appointments as $a) {
    if ($a['status'] === 'pending') $pendingAppointments++;
    elseif ($a['status'] === 'confirmed') $confirmedAppointments++;
    elseif ($a['status'] === 'completed') $completedAppointments++;
}

// Stats for doctor/nurse/admin
$allAppointments = [];
$totalPatients = 0;
$todayAppointments = [];
if (in_array($role, ['doctor', 'nurse', 'admin'])) {
    $allAppointments = $appointmentModel->getAll();
    $totalPatients = 0;
    $patientIds = [];
    foreach ($allAppointments as $a) {
        if (!in_array($a['user_id'], $patientIds)) {
            $patientIds[] = $a['user_id'];
        }
    }
    $totalPatients = count($patientIds);
    $today = date('Y-m-d');
    foreach ($allAppointments as $a) {
        if ($a['appointment_date'] === $today) {
            $todayAppointments[] = $a;
        }
    }
}

// All users for admin
$allUsers = [];
if ($role === 'admin') {
    $allUsers = $userModel->getAllUsers();
}

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper($parts[0][0] . $parts[count($parts)-1][0]);
    }
    return strtoupper(substr($name, 0, 2));
}

function getRoleLabel($role) {
    $labels = ['patient' => 'Patient', 'doctor' => 'Médecin', 'nurse' => 'Infirmier(ère)', 'admin' => 'Administrateur'];
    return $labels[$role] ?? $role;
}

function getRoleBadgeClass($role) {
    return $role;
}

function getStatusBadge($status) {
    $labels = ['pending' => 'En attente', 'confirmed' => 'Confirmé', 'cancelled' => 'Annulé', 'completed' => 'Terminé'];
    return '<span class="status-badge ' . $status . '">' . ($labels[$status] ?? $status) . '</span>';
}

$initials = getInitials($fullname);
$roleLabel = getRoleLabel($role);
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - CongoVital</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $assetBase ?>frontend/css/common.css">
    <link rel="stylesheet" href="<?= $assetBase ?>frontend/css/dashboard.css">
</head>
<body>
<div class="dashboard-layout">
    <!-- Sidebar -->
    <aside class="dashboard-sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="<?= $assetBase ?>photos/logo.jpg" alt="CongoVital">
            <h2>CongoVital</h2>
        </div>
        <div class="user-profile-sidebar">
            <div class="user-avatar-large"><?= $initials ?></div>
            <h3><?= htmlspecialchars($fullname) ?></h3>
            <span class="user-role-badge <?= getRoleBadgeClass($role) ?>"><?= $roleLabel ?></span>
        </div>
        <nav class="sidebar-nav" id="sidebar-nav">
            <?php if ($role === 'patient'): ?>
            <a class="nav-item active" data-section="overview"><span class="nav-icon">&#9632;</span> Tableau de bord</a>
            <a class="nav-item" data-section="appointments"><span class="nav-icon">&#9776;</span> Mes rendez-vous</a>
            <a class="nav-item" data-section="doctors"><span class="nav-icon">&#43;</span> Médecins</a>
            <a class="nav-item" data-section="articles"><span class="nav-icon">&#9998;</span> Conseils santé</a>
            <a class="nav-item" data-section="book"><span class="nav-icon">+</span> Prendre rendez-vous</a>
            <a class="nav-item" data-section="profile"><span class="nav-icon">&#9679;</span> Mon profil</a>
            <a class="nav-item" data-section="messages"><span class="nav-icon">&#9993;</span> Messages</a>
            <?php elseif ($role === 'doctor'): ?>
            <a class="nav-item active" data-section="overview"><span class="nav-icon">&#9632;</span> Tableau de bord</a>
            <a class="nav-item" data-section="appointments"><span class="nav-icon">&#9776;</span> Rendez-vous</a>
            <a class="nav-item" data-section="patients"><span class="nav-icon">&#9679;</span> Patients</a>
            <a class="nav-item" data-section="articles"><span class="nav-icon">&#9998;</span> Conseils santé</a>
            <a class="nav-item" data-section="profile"><span class="nav-icon">&#9679;</span> Mon profil</a>
            <?php elseif ($role === 'nurse'): ?>
            <a class="nav-item active" data-section="overview"><span class="nav-icon">&#9632;</span> Tableau de bord</a>
            <a class="nav-item" data-section="appointments"><span class="nav-icon">&#9776;</span> Rendez-vous</a>
            <a class="nav-item" data-section="articles"><span class="nav-icon">&#9998;</span> Conseils santé</a>
            <a class="nav-item" data-section="profile"><span class="nav-icon">&#9679;</span> Mon profil</a>
            <?php elseif ($role === 'admin'): ?>
            <a class="nav-item active" data-section="overview"><span class="nav-icon">&#9632;</span> Tableau de bord</a>
            <a class="nav-item" data-section="users"><span class="nav-icon">&#9679;</span> Utilisateurs</a>
            <a class="nav-item" data-section="doctors"><span class="nav-icon">&#43;</span> Médecins</a>
            <a class="nav-item" data-section="articles"><span class="nav-icon">&#9998;</span> Articles</a>
            <a class="nav-item" data-section="appointments"><span class="nav-icon">&#9776;</span> Rendez-vous</a>
            <a class="nav-item" data-section="profile"><span class="nav-icon">&#9679;</span> Mon profil</a>
            <?php endif; ?>
            <a class="nav-item logout" id="logout-btn"><span class="nav-icon">&#8617;</span> Déconnexion</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">
        <div class="dashboard-topbar">
            <button class="sidebar-toggle" id="sidebarToggle">&#9776;</button>
            <h1 id="pageTitle">Tableau de bord</h1>
            <div class="topbar-right">
                <div class="topbar-user">
                    <span><?= htmlspecialchars($fullname) ?></span>
                    <div class="topbar-avatar"><?= $initials ?></div>
                </div>
            </div>
        </div>

        <div class="dashboard-content">
            <div id="alert-container-dashboard"></div>
            <?php if ($flashMessage): ?>
            <div class="alert-dashboard show <?= $flashType === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($flashMessage) ?>
            </div>
            <?php endif; ?>

            <!-- ==================== OVERVIEW ==================== -->
            <section id="section-overview" class="dashboard-section active">
                <?php if ($role === 'patient'): ?>
                <h2 class="section-title">Bienvenue, <?= htmlspecialchars($fullname) ?></h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green">&#9776;</div>
                        <div class="stat-info">
                            <h3><?= $totalAppointments ?></h3>
                            <p>Total rendez-vous</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">&#9888;</div>
                        <div class="stat-info">
                            <h3><?= $pendingAppointments ?></h3>
                            <p>En attente</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">&#10003;</div>
                        <div class="stat-info">
                            <h3><?= $confirmedAppointments ?></h3>
                            <p>Confirmés</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">&#9733;</div>
                        <div class="stat-info">
                            <h3><?= $completedAppointments ?></h3>
                            <p>Terminés</p>
                        </div>
                    </div>
                </div>
                <?php elseif ($role === 'doctor'): ?>
                <h2 class="section-title">Tableau de bord - Médecin</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green">&#9776;</div>
                        <div class="stat-info">
                            <h3><?= count($allAppointments) ?></h3>
                            <p>Total rendez-vous</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">&#9679;</div>
                        <div class="stat-info">
                            <h3><?= $totalPatients ?></h3>
                            <p>Patients</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">&#9888;</div>
                        <div class="stat-info">
                            <h3><?= count($todayAppointments) ?></h3>
                            <p>Aujourd'hui</p>
                        </div>
                    </div>
                </div>
                <?php elseif ($role === 'nurse'): ?>
                <h2 class="section-title">Tableau de bord - Infirmier(ère)</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green">&#9776;</div>
                        <div class="stat-info">
                            <h3><?= count($allAppointments) ?></h3>
                            <p>Total rendez-vous</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">&#9679;</div>
                        <div class="stat-info">
                            <h3><?= $totalPatients ?></h3>
                            <p>Patients</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">&#9888;</div>
                        <div class="stat-info">
                            <h3><?= count($todayAppointments) ?></h3>
                            <p>Aujourd'hui</p>
                        </div>
                    </div>
                </div>
                <?php elseif ($role === 'admin'): ?>
                <h2 class="section-title">Tableau de bord - Administrateur</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon green">&#9679;</div>
                        <div class="stat-info">
                            <h3><?= $userModel->getTotalCount() ?></h3>
                            <p>Utilisateurs</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue">&#9776;</div>
                        <div class="stat-info">
                            <h3><?= count($allAppointments) ?></h3>
                            <p>Rendez-vous</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange">&#9888;</div>
                        <div class="stat-info">
                            <h3><?= $totalPatients ?></h3>
                            <p>Patients</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple">&#9733;</div>
                        <div class="stat-info">
                            <h3><?= count($todayAppointments) ?></h3>
                            <p>Aujourd'hui</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent appointments widget -->
                <div class="card">
                    <div class="card-header">
                        <h3>Rendez-vous récents</h3>
                        <?php if ($role === 'patient'): ?>
                        <a class="btn-sm btn-outline" onclick="showSection('appointments')">Voir tout</a>
                        <?php endif; ?>
                    </div>
                    <?php $recentApps = array_slice($role === 'patient' ? $appointments : $allAppointments, 0, 5); ?>
                    <?php if (count($recentApps) > 0): ?>
                    <div class="recent-appointments">
                        <?php foreach ($recentApps as $app): ?>
                        <div class="appointment-row">
                            <div class="app-info">
                                <h4><?= htmlspecialchars($app['service_type']) ?></h4>
                                <p><?= date('d/m/Y', strtotime($app['appointment_date'])) ?> à <?= substr($app['appointment_time'], 0, 5) ?></p>
                            </div>
                            <?= getStatusBadge($app['status']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#9776;</div>
                        <h3>Aucun rendez-vous</h3>
                        <p>Vous n'avez pas encore de rendez-vous.</p>
                        <?php if ($role === 'patient'): ?>
                        <br><a class="btn-sm btn-primary" onclick="showSection('book')">Prendre rendez-vous</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ==================== APPOINTMENTS LIST ==================== -->
            <section id="section-appointments" class="dashboard-section">
                <h2 class="section-title"><?= $role === 'patient' ? 'Mes rendez-vous' : 'Liste des rendez-vous' ?></h2>
                <div class="card">
                    <div class="table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <?php if ($role !== 'patient'): ?><th>Patient</th><?php endif; ?>
                                    <th>Service</th>
                                    <th>Date</th>
                                    <th>Heure</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="appointments-table-body">
                                <?php
                                $displayApps = $role === 'patient' ? $appointments : $allAppointments;
                                if (count($displayApps) > 0):
                                    foreach ($displayApps as $app):
                                ?>
                                <tr>
                                    <?php if ($role !== 'patient'): ?>
                                    <td><?= htmlspecialchars($app['fullname'] ?? $app['username'] ?? 'N/A') ?></td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars($app['service_type']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($app['appointment_date'])) ?></td>
                                    <td><?= substr($app['appointment_time'], 0, 5) ?></td>
                                    <td><?= getStatusBadge($app['status']) ?></td>
                                    <td>
                                        <?php if ($app['status'] === 'pending'): ?>
                                        <button class="btn-sm btn-success" onclick="confirmAppointment(<?= $app['id'] ?>)">Confirmer</button>
                                        <button class="btn-sm btn-danger" onclick="cancelAppointment(<?= $app['id'] ?>)">Annuler</button>
                                        <?php elseif ($app['status'] === 'confirmed'): ?>
                                        <?php if (in_array($role, ['doctor', 'nurse', 'admin'])): ?>
                                        <button class="btn-sm btn-info" onclick="completeAppointment(<?= $app['id'] ?>)">Terminer</button>
                                        <?php endif; ?>
                                        <button class="btn-sm btn-danger" onclick="cancelAppointment(<?= $app['id'] ?>)">Annuler</button>
                                        <?php else: ?>
                                        <span style="color:#999;font-size:0.85em;">--</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                    endforeach;
                                else:
                                ?>
                                <tr><td colspan="<?= $role === 'patient' ? '5' : '6' ?>" style="text-align:center;padding:30px;color:#999;">Aucun rendez-vous trouvé</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ==================== BOOK APPOINTMENT (patient only) ==================== -->
            <?php if ($role === 'patient'): ?>
            <section id="section-book" class="dashboard-section">
                <h2 class="section-title">Prendre un rendez-vous</h2>
                <div class="card form-card">
                    <div id="book-alert"></div>
                    <form id="book-appointment-form">
                        <div class="form-group">
                            <label for="book-doctor">Médecin *</label>
                            <select id="book-doctor" name="doctor_id" required>
                                <option value="">-- Sélectionnez un médecin --</option>
                                <?php foreach ($doctors as $doctor): ?>
                                <option value="<?= (int) $doctor['id'] ?>">
                                    Dr <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?> - <?= htmlspecialchars($doctor['specialty']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="book-service">Service *</label>
                            <select id="book-service" name="service_type" required>
                                <option value="">-- Sélectionnez un service --</option>
                                <option value="Consultation générale">Consultation générale</option>
                                <option value="Consultation pédiatrique">Consultation pédiatrique</option>
                                <option value="Consultation gynécologique">Consultation gynécologique</option>
                                <option value="Vaccination">Vaccination</option>
                                <option value="Soins infirmiers">Soins infirmiers</option>
                                <option value="Analyse médicale">Analyse médicale</option>
                                <option value="Suivi médical">Suivi médical</option>
                                <option value="Urgence">Urgence</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="book-date">Date *</label>
                            <input type="date" id="book-date" name="appointment_date" required min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label for="book-time">Heure *</label>
                            <input type="time" id="book-time" name="appointment_time" required>
                        </div>
                        <div class="form-group">
                            <label for="book-notes">Notes (optionnel)</label>
                            <textarea id="book-notes" name="notes" placeholder="Informations complémentaires..." rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn-submit" style="width:100%;">Prendre rendez-vous</button>
                    </form>
                </div>
            </section>
            <?php endif; ?>

            <!-- ==================== PATIENTS LIST (doctor only) ==================== -->
            <?php if ($role === 'doctor'): ?>
            <section id="section-patients" class="dashboard-section">
                <h2 class="section-title">Mes patients</h2>
                <div class="card">
                    <div class="table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Email</th>
                                    <th>Téléphone</th>
                                    <th>Dernier rendez-vous</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $patientUsers = $userModel->getUsersByRole('patient');
                                if (count($patientUsers) > 0):
                                    foreach ($patientUsers as $pu):
                                        $lastApp = [];
                                        foreach ($allAppointments as $a) {
                                            if ((int)$a['user_id'] === (int)$pu['id']) {
                                                $lastApp = $a;
                                            }
                                        }
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($pu['fullname']) ?></strong></td>
                                    <td><?= htmlspecialchars($pu['email']) ?></td>
                                    <td><?= htmlspecialchars($pu['phone'] ?? '--') ?></td>
                                    <td><?= !empty($lastApp) ? date('d/m/Y', strtotime($lastApp['appointment_date'])) : '--' ?></td>
                                </tr>
                                <?php endforeach;
                                else: ?>
                                <tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">Aucun patient trouvé</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- ==================== USERS LIST (admin only) ==================== -->
            <?php if ($role === 'admin'): ?>
            <section id="section-users" class="dashboard-section">
                <h2 class="section-title">Gestion des utilisateurs</h2>
                
                <!-- Formulaire d'ajout d'utilisateur -->
                <div class="card form-card" style="margin-bottom: 25px;">
                    <h3 style="margin-bottom:16px;font-size:1.1em;font-weight:600;">Ajouter un nouveau compte</h3>
                    <div id="admin-add-user-alert"></div>
                    <form id="admin-add-user-form">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-username">Nom d'utilisateur *</label>
                                    <input type="text" id="admin-username" required placeholder="ex: jdoe">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-fullname">Nom complet *</label>
                                    <input type="text" id="admin-fullname" required placeholder="ex: Jean Doe">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-email">Email *</label>
                                    <input type="email" id="admin-email" required placeholder="email@exemple.com">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-role">Rôle *</label>
                                    <select id="admin-role" required>
                                        <option value="patient">Patient</option>
                                        <option value="doctor">Médecin</option>
                                        <option value="nurse">Infirmier(ère)</option>
                                    </select>
                                    <small class="text-muted">Note: Vous ne pouvez pas créer d'autres administrateurs.</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-password">Mot de passe temporaire *</label>
                                    <input type="password" id="admin-password" required placeholder="8 caractères, une majuscule et un chiffre">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="admin-phone">Téléphone</label>
                                    <input type="text" id="admin-phone" placeholder="+243 ...">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn-submit">Créer le compte</button>
                    </form>
                </div>

                <div class="card">
                    <div class="table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Email</th>
                                    <th>Rôle</th>
                                    <th>Téléphone</th>
                                    <th>Statut</th>
                                    <th>Inscription</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $au): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($au['fullname']) ?></strong></td>
                                    <td><?= htmlspecialchars($au['email']) ?></td>
                                    <td><span class="user-role-badge <?= $au['role'] ?>" style="font-size:0.75em;"><?= getRoleLabel($au['role']) ?></span></td>
                                    <td><?= htmlspecialchars($au['phone'] ?? '--') ?></td>
                                    <td><?= (int)$au['is_active'] ? '<span style="color:green;">Actif</span>' : '<span style="color:red;">Inactif</span>' ?></td>
                                    <td><?= date('d/m/Y', strtotime($au['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- ==================== DOCTORS DIRECTORY ==================== -->
            <?php if (in_array($role, ['patient', 'admin'])): ?>
            <section id="section-doctors" class="dashboard-section">
                <h2 class="section-title"><?= $role === 'admin' ? 'Gestion des médecins' : 'Annuaire des médecins' ?></h2>

                <?php if ($role === 'admin'): ?>
                <div class="card form-card" style="margin-bottom: 25px;">
                    <h3 style="margin-bottom:16px;font-size:1.1em;font-weight:600;">Ajouter un médecin</h3>
                    <form method="post">
                        <input type="hidden" name="dashboard_action" value="save_doctor">
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Prénom *</label><input type="text" name="first_name" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Nom *</label><input type="text" name="last_name" required></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Spécialité *</label><input type="text" name="specialty" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Téléphone</label><input type="text" name="phone"></div></div>
                        </div>
                        <div class="form-group"><label>Adresse *</label><input type="text" name="address" required></div>
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Ville</label><input type="text" name="city" value="Kinshasa"></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Horaires</label><input type="text" name="opening_hours" placeholder="Lun-Ven 08:00-17:00"></div></div>
                        </div>
                        <button type="submit" class="btn-submit">Ajouter le médecin</button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="table-container">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Médecin</th>
                                    <th>Spécialité</th>
                                    <th>Adresse</th>
                                    <th>Téléphone</th>
                                    <th>Horaires</th>
                                    <?php if ($role === 'admin'): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doctors as $doctor): ?>
                                <tr>
                                    <?php if ($role === 'admin'): ?>
                                    <form method="post">
                                        <input type="hidden" name="dashboard_action" value="save_doctor">
                                        <input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>">
                                        <td>
                                            <input type="text" name="first_name" value="<?= htmlspecialchars($doctor['first_name']) ?>" required style="margin-bottom:6px;">
                                            <input type="text" name="last_name" value="<?= htmlspecialchars($doctor['last_name']) ?>" required>
                                        </td>
                                        <td><input type="text" name="specialty" value="<?= htmlspecialchars($doctor['specialty']) ?>" required></td>
                                        <td>
                                            <input type="text" name="address" value="<?= htmlspecialchars($doctor['address']) ?>" required style="margin-bottom:6px;">
                                            <input type="text" name="city" value="<?= htmlspecialchars($doctor['city'] ?? '') ?>">
                                        </td>
                                        <td><input type="text" name="phone" value="<?= htmlspecialchars($doctor['phone'] ?? '') ?>"></td>
                                        <td><input type="text" name="opening_hours" value="<?= htmlspecialchars($doctor['opening_hours'] ?? '') ?>"></td>
                                        <td>
                                            <button type="submit" class="btn-sm btn-success">Modifier</button>
                                    </form>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce médecin ?');">
                                                <input type="hidden" name="dashboard_action" value="delete_doctor">
                                                <input type="hidden" name="doctor_id" value="<?= (int) $doctor['id'] ?>">
                                                <button type="submit" class="btn-sm btn-danger">Supprimer</button>
                                            </form>
                                        </td>
                                    <?php else: ?>
                                    <td><strong>Dr <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($doctor['specialty']) ?></td>
                                    <td><?= htmlspecialchars($doctor['address']) ?><?= !empty($doctor['city']) ? ', ' . htmlspecialchars($doctor['city']) : '' ?></td>
                                    <td><?= htmlspecialchars($doctor['phone'] ?? '--') ?></td>
                                    <td><?= htmlspecialchars($doctor['opening_hours'] ?? '--') ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- ==================== HEALTH ARTICLES ==================== -->
            <section id="section-articles" class="dashboard-section">
                <h2 class="section-title"><?= $role === 'admin' ? 'Gestion des articles de conseils' : 'Conseils santé' ?></h2>

                <?php if ($role === 'admin'): ?>
                <div class="card form-card" style="margin-bottom: 25px;">
                    <h3 style="margin-bottom:16px;font-size:1.1em;font-weight:600;">Ajouter un article</h3>
                    <form method="post">
                        <input type="hidden" name="dashboard_action" value="save_article">
                        <div class="row">
                            <div class="col-md-8"><div class="form-group"><label>Titre *</label><input type="text" name="title" required></div></div>
                            <div class="col-md-4"><div class="form-group"><label>Catégorie *</label><input type="text" name="category" required placeholder="prevention"></div></div>
                        </div>
                        <div class="form-group"><label>Contenu *</label><textarea name="content" rows="4" required></textarea></div>
                        <button type="submit" class="btn-submit">Publier l’article</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($role === 'admin'): ?>
                <div class="card">
                    <div class="table-container">
                        <table class="dashboard-table">
                            <thead><tr><th>Titre</th><th>Catégorie</th><th>Contenu</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($articles as $article): ?>
                                <tr>
                                    <form method="post">
                                        <input type="hidden" name="dashboard_action" value="save_article">
                                        <input type="hidden" name="article_id" value="<?= (int) $article['id'] ?>">
                                        <td><input type="text" name="title" value="<?= htmlspecialchars($article['title']) ?>" required></td>
                                        <td><input type="text" name="category" value="<?= htmlspecialchars($article['category']) ?>" required></td>
                                        <td><textarea name="content" rows="3" required><?= htmlspecialchars($article['content']) ?></textarea></td>
                                        <td>
                                            <button type="submit" class="btn-sm btn-success">Modifier</button>
                                    </form>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cet article ?');">
                                                <input type="hidden" name="dashboard_action" value="delete_article">
                                                <input type="hidden" name="article_id" value="<?= (int) $article['id'] ?>">
                                                <button type="submit" class="btn-sm btn-danger">Supprimer</button>
                                            </form>
                                        </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="stats-grid">
                    <?php foreach ($articles as $article): ?>
                    <article class="stat-card" style="align-items:flex-start;">
                        <div class="stat-info">
                            <p><?= htmlspecialchars($article['category']) ?> | <?= date('d/m/Y', strtotime($article['published_at'])) ?></p>
                            <h3 style="font-size:1.1rem;"><?= htmlspecialchars($article['title']) ?></h3>
                            <p><?= htmlspecialchars($article['content']) ?></p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <!-- ==================== PROFILE ==================== -->
            <section id="section-profile" class="dashboard-section">
                <h2 class="section-title">Mon profil</h2>
                <div class="card">
                    <div class="profile-header">
                        <div class="profile-avatar"><?= $initials ?></div>
                        <div class="profile-info">
                            <h2><?= htmlspecialchars($fullname) ?></h2>
                            <p><?= $roleLabel ?> | <?= htmlspecialchars($email) ?></p>
                        </div>
                    </div>
                    <div class="profile-detail-grid">
                        <div class="profile-detail-item">
                            <label>Nom complet</label>
                            <span><?= htmlspecialchars($fullname) ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Nom d'utilisateur</label>
                            <span><?= htmlspecialchars($username) ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Email</label>
                            <span><?= htmlspecialchars($email) ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Téléphone</label>
                            <span><?= htmlspecialchars($phone ?: 'Non renseigné') ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Genre</label>
                            <span><?= $gender === 'male' ? 'Homme' : ($gender === 'female' ? 'Femme' : ($gender === 'other' ? 'Autre' : 'Non renseigné')) ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Date de naissance</label>
                            <span><?= $dateOfBirth ? date('d/m/Y', strtotime($dateOfBirth)) : 'Non renseignée' ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Rôle</label>
                            <span><?= $roleLabel ?></span>
                        </div>
                        <div class="profile-detail-item">
                            <label>Membre depuis</label>
                            <span><?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
                <div class="card form-card" style="margin-top:20px;">
                    <h3 style="margin-bottom:16px;font-size:1.1em;font-weight:600;">Modifier le profil</h3>
                    <div id="profile-alert"></div>
                    <form id="update-profile-form">
                        <div class="form-group">
                            <label for="edit-fullname">Nom complet</label>
                            <input type="text" id="edit-fullname" value="<?= htmlspecialchars($fullname) ?>">
                        </div>
                        <div class="form-group">
                            <label for="edit-phone">Téléphone</label>
                            <input type="text" id="edit-phone" value="<?= htmlspecialchars($phone) ?>" placeholder="+243 XX XXX XXXX">
                        </div>
                        <button type="submit" class="btn-submit">Mettre à jour</button>
                    </form>
                </div>
            </section>

            <!-- ==================== MESSAGES (patient only) ==================== -->
            <?php if ($role === 'patient'): ?>
            <section id="section-messages" class="dashboard-section">
                <h2 class="section-title">Messages</h2>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-icon">&#9993;</div>
                        <h3>Aucun message</h3>
                        <p>Vous n'avez pas encore de messages. Les communications de votre médecin apparaîtront ici.</p>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
const API_BASE_URL = './<?= $assetBase ?>backend/api';
const DASHBOARD_LOGIN_URL = './<?= $assetBase ?>frontend/html/abonne.html';
const DASHBOARD_HOME_URL = './<?= $assetBase ?>index.html';
const CURRENT_USER_ID = <?= $userId ?>;
const CURRENT_ROLE = '<?= $role ?>';
</script>
<script src="<?= $assetBase ?>frontend/js/dashboard.js"></script>
</body>
</html>
