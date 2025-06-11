import { createRouter, createWebHistory } from 'vue-router';
import MenuPage from '@/pages/game/MenuPage.vue';
import MatchmakingPage from '@/pages/game/MatchmakingPage.vue';
import GamePage from '@/pages/game/GamePage.vue';
import GameOverPage from '@/pages/game/GameOverPage.vue';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/game',
      children: [
        {
          path: 'menu',
          name: 'game-menu',
          component: MenuPage
        },
        {
          path: 'matchmaking',
          name: 'game-matchmaking',
          component: MatchmakingPage
        },
        {
          path: 'play',
          name: 'game-play',
          component: GamePage
        },
        {
          path: 'over',
          name: 'game-over',
          component: GameOverPage
        }
      ]
    },
    {
      path: '/',
      redirect: '/game/menu'
    }
  ]
});

export default router; 