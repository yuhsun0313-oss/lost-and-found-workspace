import { createRouter, createWebHistory } from 'vue-router'
import ItemDetail from '../views/ItemDetail.vue'
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/item/:id', // 👈 動態路由參數，:id 代表可以接收 1, 2, 3 這種物品編號
      name: 'item-detail',
      component: ItemDetail
    }
  ],
})

export default router
