
<?php
session_start();
require "db.php";

function safe($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function money($value) {
    return "RWF " . number_format((float)$value, 2);
}

if (empty($_SESSION["token"])) {
    $_SESSION["token"] = bin2hex(random_bytes(32));
}

$categories = [
    "Salary", "Food", "Transport", "Rent",
    "Utilities", "Shopping", "Health", "Other"
];

$error = "";

// BACKEND: SAVE OR DELETE
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals(
        $_SESSION["token"],
        $_POST["token"] ?? ""
    )) {
        exit("Invalid request.");
    }

    $action = $_POST["action"] ?? "";

    if ($action === "save") {
        $title = trim($_POST["title"] ?? "");
        $type = $_POST["type"] ?? "";
        $category = $_POST["category"] ?? "";
        $amount = $_POST["amount"] ?? "";
        $date = $_POST["date"] ?? "";
        $note = trim($_POST["note"] ?? "");

        $validDate = DateTime::createFromFormat("!Y-m-d", $date);

        if (
            $title === "" ||
            strlen($title) > 100 ||
            !in_array($type, ["income", "expense"], true) ||
            !in_array($category, $categories, true) ||
            !is_numeric($amount) ||
            $amount <= 0 ||
            $amount > 9999999999.99 ||
            !$validDate ||
            $validDate->format("Y-m-d") !== $date ||
            strlen($note) > 255
        ) {
            $error = "Please enter valid transaction details.";
        } else {
            $sql = "INSERT INTO transactions
                    (title, type, category, amount, note, transaction_date)
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $title, $type, $category,
                $amount, $note, $date
            ]);

            header("Location: index.php");
            exit;
        }
    }

    if ($action === "delete") {
        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

        if ($id && $id > 0) {
            $stmt = $db->prepare(
                "DELETE FROM transactions WHERE id = ?"
            );
            $stmt->execute([$id]);
        }

        header("Location: index.php?page=reports");
        exit;
    }
}

// BACKEND: CALCULATE TOTALS
$totals = $db->query("
    SELECT
      COALESCE(SUM(CASE WHEN type='income'
        THEN amount ELSE 0 END), 0) AS income,
      COALESCE(SUM(CASE WHEN type='expense'
        THEN amount ELSE 0 END), 0) AS expense,
      COALESCE(SUM(CASE WHEN type='expense'
        AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND transaction_date < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
        THEN amount ELSE 0 END), 0) AS monthly
    FROM transactions
")->fetch(PDO::FETCH_ASSOC);

$balance = $totals["income"] - $totals["expense"];

$transactions = $db->query("
    SELECT * FROM transactions
    ORDER BY transaction_date DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$categoryTotals = $db->query("
    SELECT category, SUM(amount) AS total
    FROM transactions
    WHERE type = 'expense'
    GROUP BY category
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page = $_GET["page"] ?? "dashboard";
if (!in_array($page, [
    "dashboard", "add", "categories", "reports"
], true)) {
    $page = "dashboard";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">
    <title>E-Track</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
    <h1>💰 E-Track</h1>
    <nav>
        <a href="index.php"
           class="<?= $page === 'dashboard' ? 'active' : '' ?>">
           Dashboard</a>
        <a href="?page=add"
           class="<?= $page === 'add' ? 'active' : '' ?>">
           Add Expense</a>
        <a href="?page=categories"
           class="<?= $page === 'categories' ? 'active' : '' ?>">
           Categories</a>
        <a href="?page=reports"
           class="<?= $page === 'reports' ? 'active' : '' ?>">
           Reports</a>
    </nav>
</header>

<main>

<?php if ($page === "dashboard"): ?>

<div class="cards">
    <div class="card purple">
        <small>TOTAL BALANCE</small>
        <h2><?= money($balance) ?></h2>
        <p>Current balance</p>
    </div>

    <div class="card green">
        <small>TOTAL INCOME</small>
        <h2><?= money($totals["income"]) ?></h2>
        <p>All time income</p>
    </div>

    <div class="card orange">
        <small>TOTAL EXPENSES</small>
        <h2><?= money($totals["expense"]) ?></h2>
        <p>All time expenses</p>
    </div>

    <div class="card teal">
        <small>THIS MONTH</small>
        <h2><?= money($totals["monthly"]) ?></h2>
        <p>Current month spending</p>
    </div>
</div>

<section class="panel">
    <div class="section-heading">
        <h2>Recent Transactions</h2>
        <a class="btn" href="?page=reports">View All</a>
    </div>

    <?php foreach (array_slice($transactions, 0, 5) as $t): ?>
        <div class="transaction">
            <div>
                <strong><?= safe($t["title"]) ?></strong>
                <p><?= safe($t["note"]) ?></p>
                <small><?= safe($t["transaction_date"]) ?></small>
            </div>
            <b class="<?= safe($t["type"]) ?>">
                <?= $t["type"] === "income" ? "+" : "-" ?>
                <?= money($t["amount"]) ?>
            </b>
        </div>
    <?php endforeach; ?>

    <?php if (!$transactions): ?>
        <p>No transactions yet. Add your first record.</p>
    <?php endif; ?>
</section>

<?php elseif ($page === "add"): ?>

<section class="panel form-panel">
    <h2>Add Transaction</h2>
    <p>Record your income or expense.</p>

    <?php if ($error): ?>
        <p class="error"><?= safe($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="token"
               value="<?= safe($_SESSION["token"]) ?>">
        <input type="hidden" name="action" value="save">

        <label>Transaction title</label>
        <input name="title" maxlength="100"
               placeholder="e.g. Food shopping" required>

        <label>Transaction type</label>
        <select name="type" required>
            <option value="expense">Expense</option>
            <option value="income">Income</option>
        </select>

        <label>Category</label>
        <select name="category" required>
            <?php foreach ($categories as $category): ?>
                <option value="<?= safe($category) ?>">
                    <?= safe($category) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Amount (RWF)</label>
        <input type="number" name="amount"
               min="0.01" max="9999999999.99"
               step="0.01" required>

        <label>Date</label>
        <input type="date" name="date"
               value="<?= date('Y-m-d') ?>" required>

        <label>Description (optional)</label>
        <textarea name="note" maxlength="255"
                  placeholder="Add notes"></textarea>

        <button type="submit">Save Transaction</button>
    </form>
</section>

<?php elseif ($page === "categories"): ?>

<section class="panel">
    <h2>Expense Categories</h2>
    <p>Your spending by category.</p>

    <?php foreach ($categoryTotals as $c): ?>
        <div class="transaction">
            <strong><?= safe($c["category"]) ?></strong>
            <b class="expense"><?= money($c["total"]) ?></b>
        </div>
    <?php endforeach; ?>

    <?php if (!$categoryTotals): ?>
        <p>No expenses recorded yet.</p>
    <?php endif; ?>
</section>

<?php elseif ($page === "reports"): ?>

<section class="panel">
    <h2>Transaction Reports</h2>
    <p>
        Income: <?= money($totals["income"]) ?> |
        Expenses: <?= money($totals["expense"]) ?>
    </p>

    <?php foreach ($transactions as $t): ?>
        <div class="transaction">
            <div>
                <strong><?= safe($t["title"]) ?></strong>
                <p>
                    <?= safe($t["category"]) ?> |
                    <?= safe($t["transaction_date"]) ?>
                </p>
                <small><?= safe($t["note"]) ?></small>
            </div>

            <div class="transaction-actions">
                <b class="<?= safe($t["type"]) ?>">
                    <?= $t["type"] === "income" ? "+" : "-" ?>
                    <?= money($t["amount"]) ?>
                </b>

                <form method="POST"
                      onsubmit="return confirm('Delete this transaction?')">
                    <input type="hidden" name="token"
                           value="<?= safe($_SESSION["token"]) ?>">
                    <input type="hidden" name="action"
                           value="delete">
                    <input type="hidden" name="id"
                           value="<?= (int)$t["id"] ?>">
                    <button class="delete" type="submit">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$transactions): ?>
        <p>No transactions available.</p>
    <?php endif; ?>
</section>

<?php endif; ?>

</main>
</body>
</html>
  