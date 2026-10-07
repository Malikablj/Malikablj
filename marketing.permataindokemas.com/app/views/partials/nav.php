<?php

use App\Helpers\Navigation;

/** @var list<array{label:string,items:list<array{label:string,icon:string,path:string,permission:string}>}> $groups */
?>
<?php foreach ($groups as $group): ?>
    <div class="nav-group">
        <?php if ($group['label'] !== ''): ?>
            <div class="nav-group-label"><?= e($group['label']) ?></div>
        <?php endif; ?>
        <ul class="nav-list">
            <?php foreach ($group['items'] as $item): $active = Navigation::isActive($item['path']); ?>
                <li>
                    <a href="<?= e(url($item['path'])) ?>" class="nav-item-link<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?> title="<?= e($item['label']) ?>">
                        <i class="bi <?= e($item['icon']) ?>"></i>
                        <span class="nav-text"><?= e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endforeach; ?>
