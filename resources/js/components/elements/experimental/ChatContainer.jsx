import { Flex } from '@chakra-ui/react'
import React from 'react'

const ChatContainer = ({ children, side = 'left' }) => {
  return (
    <Flex
      justify={side === 'left' ? 'flex-start' : 'flex-end'}
      gap={2}
      alignItems="start"
    >
      {children}
    </Flex>
  )
}

export default ChatContainer
