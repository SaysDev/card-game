        <h2 class="text-xl sm:text-2xl font-semibold text-center mb-4">
          Grę wygrał {{ gameState.winner ? getDisplayName(gameState.winner.username, gameState.winner.user_id) : 'Nieznany gracz' }}!
        </h2>
