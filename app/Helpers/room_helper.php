<?php

function get_group_users(array $roomUsers, string $userId): array
{
    return array_values(array_filter($roomUsers, fn($id) => $id != $userId));
}

function get_group_admins(array $admins, string $userId): array
{
    return array_values(array_filter($admins, fn($id) => $id != $userId));
}
